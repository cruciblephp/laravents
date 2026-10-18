<?php

declare(strict_types=1);

namespace Laravents\Auth;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Bus\ContextMetadata;
use LogicException;

/**
 * Puts a user on a guard for a while and puts the guard back as it was afterwards. The queue worker uses it to
 * run an asynchronous handler as its publication's actor; Laravents::as($user) uses it for one dispatch. Either
 * way the guard is what vouches for the actor: #[Authorize] and #[CurrentUser] read the user from it, so there is
 * no other trust path.
 */
final readonly class ActAs
{
    public function __construct(
        private AuthManager $auth,
        private ContextMetadata $metadata,
    ) {}

    /**
     * Runs the callback with the user on the guard (the default when null) and the actor keys in Context naming
     * that user, so the metadata pipe gives every message dispatched inside it that actor rather than a parent's.
     * Afterwards the guard and the Context keys are as they were, also on an exception.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Authenticatable $user, ?string $guard, Closure $callback): mixed
    {
        $id = $user->getAuthIdentifier();

        if (! is_string($id) && ! is_int($id)) {
            throw new LogicException(sprintf('Laravents::as() needs a user with a string or integer identifier, %s given.', get_debug_type($id)));
        }

        $name = $guard ?? $this->auth->getDefaultDriver();
        $restore = $this->put($user, $name);

        try {
            return $this->metadata->within(
                [...$this->metadata->current(), 'actor_id' => (string) $id, 'actor_guard' => $name],
                $callback,
            );
        } finally {
            $restore();
        }
    }

    /**
     * Puts the user on the guard and returns how to put the guard back as it was: a worker, where nobody is
     * logged in, ends clean, and a process that had a user keeps them.
     *
     * @return Closure(): void
     */
    public function put(Authenticatable $user, ?string $guard): Closure
    {
        $auth = $this->auth;
        $instance = $auth->guard($guard);
        $previous = $instance->hasUser() ? $instance->user() : null;
        $instance->setUser($user);

        return static function () use ($auth, $instance, $previous): void {
            if ($previous instanceof Authenticatable) {
                $instance->setUser($previous);
            } elseif (method_exists($instance, 'forgetUser')) {
                $instance->forgetUser();
            } else {
                $auth->forgetGuards();
            }
        };
    }
}
