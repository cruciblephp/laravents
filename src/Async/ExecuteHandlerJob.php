<?php

declare(strict_types=1);

namespace Laravents\Async;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Date;
use Laravents\Auth\ActAs;
use Laravents\Bus\Engine;
use Laravents\Discovery\Registry;
use Laravents\Outbox\EventPublication;
use LogicException;

/**
 * Runs one #[Asynchronous] event handler on the queue, after the publisher's transaction commits. The job
 * carries only the publication id and the database connection the row is on: the outbox row holds the event,
 * every header it was published with and the actor, so first delivery and laravents:republish share this one
 * code path. The queue retries a failed run up to $tries times; failed_jobs is the dead-letter store.
 */
final class ExecuteHandlerJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** Handed over by whoever pushes the job, else loaded on first use; never serialized, so the payload holds only the id. */
    private ?EventPublication $publication = null;

    /**
     * @param  string|null  $publicationConnection  The database connection the row was written on, null for the
     *                                              default. Not $connection: Queueable's queue connection has that name.
     */
    public function __construct(public readonly string $publicationId, public readonly ?string $publicationConnection = null)
    {
        $tries = config('laravents.async.tries');
        $this->tries = is_int($tries) && $tries > 0 ? $tries : 3;

        $connection = config('laravents.async.connection');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    /**
     * The row this job delivers, as the caller that pushes it already holds it (HandleMessage just wrote it,
     * laravents:republish just listed it), so displayName() and tags() read it without a query on the push path.
     * Not serialized: the worker loads the row itself.
     */
    public function withPublication(EventPublication $publication): static
    {
        $this->publication = $publication;

        return $this;
    }

    public function handle(Engine $engine, Registry $registry, AuthManager $auth, ActAs $actAs): void
    {
        // A missing row, listener or actor, or a corrupt payload, is permanent: fail at once, as CallQueuedHandler
        // does for a model SerializesModels cannot find. Only what the handler throws is left to the queue's retries.
        try {
            $publication = $this->publication = EventPublication::on($this->publicationConnection)->findOrFail($this->publicationId);
            $definition = $registry->handler($publication->listener)
                ?? throw new LogicException(sprintf('No handler [%s] is registered.', $publication->listener));
            $actor = $this->actor($publication, $auth);
            $message = $publication->message();
        } catch (ModelNotFoundException|LogicException $exception) {
            $this->fail($exception);

            return;
        }

        $restore = $actor instanceof Authenticatable ? $actAs->put($actor, $publication->actor_guard) : null;

        try {
            $engine->replay($message, $definition);

            // Outside the handler's transaction: if this write fails after the handler committed, the queue
            // delivers again, which is why handlers are idempotent on publication_id.
            $publication->forceFill(['completed_at' => Date::now()])->save();
        } finally {
            if ($restore instanceof Closure) {
                $restore();
            }
        }
    }

    /**
     * For Horizon's dashboard, which calls it when the job is pushed: laravents, module:<Module> and the routing
     * key. No query on the push path, which hands the job its row; one query only when it is asked of a job that
     * was unserialized and has not loaded its row yet.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $publication = $this->publication();

        if (! $publication instanceof EventPublication) {
            return ['laravents'];
        }

        $module = app(Registry::class)->handler($publication->listener)?->module;

        return ['laravents', ...($module === null ? [] : ['module:'.$module]), $publication->routing_key];
    }

    /**
     * The listener, Class@method, as Horizon and queue:failed show the job. Laravel's queue calls it on every push
     * (Queue::createObjectPayload() stores it in the payload); the push path hands the job its row, so that costs no
     * query. One query only when Horizon or queue:failed asks it of a job that was unserialized; tags() then reuses
     * the loaded row.
     */
    public function displayName(): string
    {
        return $this->publication()->listener ?? 'Laravents publication '.$this->publicationId;
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return array_values(array_diff(array_keys(get_object_vars($this)), ['publication']));
    }

    private function publication(): ?EventPublication
    {
        return $this->publication ??= EventPublication::on($this->publicationConnection)->find($this->publicationId);
    }

    /**
     * The user the row names, rebuilt through the guard's own provider. A user who is gone fails the job at once.
     */
    private function actor(EventPublication $publication, AuthManager $auth): ?Authenticatable
    {
        if ($publication->actor_id === null) {
            return null;
        }

        $guard = $auth->guard($publication->actor_guard);
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        if (! $provider instanceof UserProvider) {
            throw new LogicException(sprintf('The guard [%s] has no user provider, so publication [%s] cannot rebuild its actor.', $publication->actor_guard, $publication->id));
        }

        return $provider->retrieveById($publication->actor_id)
            ?? throw ($provider instanceof EloquentUserProvider
                ? (new ModelNotFoundException)->setModel($provider->getModel(), [$publication->actor_id])
                : new ModelNotFoundException(sprintf('No user [%s] on the [%s] guard.', $publication->actor_id, $publication->actor_guard)));
    }
}
