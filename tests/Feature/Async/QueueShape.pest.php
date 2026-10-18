<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Modules\Band\Api\NicknameGiven;
use Modules\Press\Handlers\HeadlineOnNicknameGiven;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

function pushedJob(): ExecuteHandlerJob
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new RuntimeException('The queue is not faked.');
    }

    $jobs = $queue->pushed(ExecuteHandlerJob::class);
    expect($jobs)->toHaveCount(1);
    $job = $jobs->first();

    if (! $job instanceof ExecuteHandlerJob) {
        throw new RuntimeException('No ExecuteHandlerJob was pushed.');
    }

    return $job;
}

/** Puts the application's queue manager back behind the facade, so a push goes to the database queue. */
function unfakeQueue(): void
{
    $queue = Queue::getFacadeRoot();

    if ($queue instanceof QueueFake) {
        Queue::swap($queue->queue);
    }
}

/**
 * Every statement run from here on, lower-cased, in order.
 *
 * @return ArrayObject<int, string>
 */
function statements(): ArrayObject
{
    /** @var ArrayObject<int, string> $statements */
    $statements = new ArrayObject;
    DB::listen(static function (QueryExecuted $query) use ($statements): void {
        $statements[] = strtolower($query->sql);
    });

    return $statements;
}

/** @return array<mixed> The payload of the one job in the jobs table. */
function pushedPayload(): array
{
    $payload = DB::table('jobs')->sole()->payload ?? null;
    $decoded = is_string($payload) ? json_decode($payload, true) : null;

    if (! is_array($decoded)) {
        throw new RuntimeException('The jobs table holds no job payload.');
    }

    return $decoded;
}

/** @param ArrayObject<int, string> $statements */
function selectsOfPublications(ArrayObject $statements): int
{
    $table = (new EventPublication)->getTable();

    return count(array_filter(
        $statements->getArrayCopy(),
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "'.$table.'"'),
    ));
}

test('publishing NicknameGiven pushes one ExecuteHandlerJob on the press queue, with its tags, display name and tries; its row holds the event and the headers of the publish', function (): void {
    config(['laravents.async.tries' => 5]);
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    Laravents::withHeader('tenant', 'acme')->publish(new NicknameGiven(7, $user->id, 2, 'The Quiet One'));

    Queue::assertCount(1);
    $job = pushedJob();
    $publication = publicationOf($job);

    expect($job->queue)->toBe('press')
        ->and($job->connection)->toBeNull()
        ->and($job->displayName())->toBe('Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke')
        ->and($job->tags())->toBe(['laravents', 'module:Press', NicknameGiven::class])
        ->and($job->tries)->toBe(5)
        ->and($publication->listener)->toBe(HeadlineOnNicknameGiven::class.'@__invoke')
        ->and($publication->routing_key)->toBe(NicknameGiven::class)
        ->and($publication->event_class)->toBe(NicknameGiven::class)
        ->and($publication->message()->payload)->toEqual(new NicknameGiven(7, $user->id, 2, 'The Quiet One'))
        ->and($publication->completed_at)->toBeNull();

    $headers = $publication->headers;
    $keys = array_keys($headers);
    sort($keys);
    $this->assertIsString($headers['message_id']);

    expect($keys)->toBe(['actor_guard', 'actor_id', 'correlation_id', 'message_id', 'tenant', 'timestamp'])
        ->and($headers['tenant'])->toBe('acme')
        ->and(Str::isUlid($headers['message_id']))->toBeTrue()
        ->and($headers['correlation_id'])->toBe($headers['message_id'])
        ->and($headers['actor_id'])->toBe((string) $user->id)
        ->and($headers['actor_guard'])->toBe('web')
        ->and([$publication->message_id, $publication->correlation_id, $publication->causation_id, $publication->actor_id, $publication->actor_guard])
        ->toBe([$headers['message_id'], $headers['correlation_id'], null, (string) $user->id, 'web']);
});

test('the job payload holds the publication id and nothing loaded from the row', function (): void {
    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));
    $job = pushedJob();
    $job->displayName();

    $copy = unserialize(serialize($job));
    $this->assertInstanceOf(ExecuteHandlerJob::class, $copy);

    expect(serialize($job))->not->toContain('event_payload')
        ->and($copy->publicationId)->toBe($job->publicationId);
});

test('laravents.async.connection, when set, is the connection of the job', function (): void {
    config(['laravents.async.connection' => 'redis']);

    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));

    expect(pushedJob()->connection)->toBe('redis');
});

test('a laravents.async.tries that is not a positive int falls back to 3, and an empty async connection is the default one', function (): void {
    config(['laravents.async.tries' => 0, 'laravents.async.connection' => '']);

    $job = new ExecuteHandlerJob('01JTEST');

    expect($job->tries)->toBe(3)
        ->and($job->connection)->toBeNull();

    config(['laravents.async.tries' => '5', 'laravents.async.connection' => null]);

    expect((new ExecuteHandlerJob('01JTEST'))->tries)->toBe(3);
});

test('an event published by a guest has no actor in its row', function (): void {
    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));

    $publication = publicationOf(pushedJob());

    expect($publication->actor_id)->toBeNull()
        ->and($publication->actor_guard)->toBeNull()
        ->and($publication->headers)->not->toHaveKey('actor_id');
});

test('a publish with an asynchronous listener inserts the row and pushes the job without selecting the row back', function (): void {
    unfakeQueue();
    $table = (new EventPublication)->getTable();
    $statements = statements();

    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));

    $inserts = array_values(array_filter($statements->getArrayCopy(), static fn (string $sql): bool => str_starts_with($sql, 'insert')));
    $payload = pushedPayload();

    expect($inserts)->toHaveCount(2)
        ->and($inserts[0])->toContain('into "'.$table.'"')
        ->and($inserts[1])->toContain('into "jobs"')
        ->and(selectsOfPublications($statements))->toBe(0)
        ->and($payload['displayName'])->toBe(HeadlineOnNicknameGiven::class.'@__invoke');
});

test('the job as pushed answers displayName() and tags() from the row it was handed, without a query', function (): void {
    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));
    $job = pushedJob();
    $statements = statements();

    expect($job->displayName())->toBe(HeadlineOnNicknameGiven::class.'@__invoke')
        ->and($job->tags())->toBe(['laravents', 'module:Press', NicknameGiven::class])
        ->and($statements)->toHaveCount(0);
});

test('an unserialized job loads its row on displayName(), one query, and tags() reuses it', function (): void {
    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));
    $copy = unserialize(serialize(pushedJob()));
    $this->assertInstanceOf(ExecuteHandlerJob::class, $copy);
    $statements = statements();

    expect($copy->displayName())->toBe(HeadlineOnNicknameGiven::class.'@__invoke')
        ->and(selectsOfPublications($statements))->toBe(1)
        ->and($copy->tags())->toBe(['laravents', 'module:Press', NicknameGiven::class])
        ->and($statements)->toHaveCount(1);
});

test('laravents:republish hands each job the row it listed: no select for the row beyond its own listing query', function (): void {
    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));
    $publication = publicationOf(pushedJob());
    $publication->forceFill(['dispatched_at' => null])->save();
    unfakeQueue();
    $statements = statements();

    Artisan::call('laravents:republish');

    $payload = pushedPayload();

    expect(Artisan::output())->toContain('1 publication republished.')
        ->and(selectsOfPublications($statements))->toBe(1)
        ->and($payload['displayName'])->toBe(HeadlineOnNicknameGiven::class.'@__invoke');
});
