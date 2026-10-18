<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Bus\Engine;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Acknowledge;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Post;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Reject;
use Laravents\Tests\Fixtures\Async\Ledger\Entries;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\LedgerHandlers;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\PostedListener;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
    Entries::createTable();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Async'))->build([
        LedgerHandlers::class,
        PostedListener::class,
    ]));
});

/** What a Ledger handler recorded, by name. */
function ledgerRecord(string $name): mixed
{
    foreach (Recorder::calls() as [$handler, $payload]) {
        if ($handler === $name) {
            return $payload;
        }
    }

    throw new RuntimeException(sprintf('Nothing recorded as [%s].', $name));
}

function ledgerIds(string $name): Ids
{
    $ids = ledgerRecord($name);

    if (! $ids instanceof Ids) {
        throw new RuntimeException(sprintf('[%s] recorded no ids.', $name));
    }

    return $ids;
}

test('A1 (fixture): across the queue the event keeps the command\'s correlation, runs as the publisher, and a command sent by the replayed handler names the event as its cause', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    Laravents::command(new Post(5));

    expect(Recorder::handlers())->toBe(['post'])
        ->and(DB::table('jobs')->count())->toBe(1);

    runWorker('ledger');

    $post = ledgerIds('post');
    $onPosted = ledgerIds('onPosted');
    $acknowledge = ledgerIds('acknowledge');

    expect($onPosted->correlationId)->toBe($post->correlationId)
        ->and($onPosted->causationId)->toBe($post->messageId)
        ->and($onPosted->context)->toBe($onPosted->headers())
        ->and(ledgerRecord('onPosted: user'))->toBe($user->id)
        ->and($acknowledge->causationId)->toBe($onPosted->messageId)
        ->and($acknowledge->correlationId)->toBe($post->correlationId)
        ->and(ledgerRecord('acknowledge: actor_id'))->toBe((string) $user->id);
});

test('a replayed handler sees the ids in the message headers, not the ids Laravel hydrated into Context', function (): void {
    $message = new Message(HandlerKind::Event, Posted::class, new Posted(5), [
        'message_id' => 'event-message',
        'correlation_id' => 'event-correlation',
        'causation_id' => 'event-cause',
    ]);

    // Foreign ids in Context at push time travel in the payload, so the worker hydrates them before the job runs.
    Context::add([
        'message_id' => 'foreign-message',
        'correlation_id' => 'foreign-correlation',
        'causation_id' => 'foreign-cause',
        'actor_id' => '999999',
        'actor_guard' => 'web',
    ]);
    $publication = EventPublication::record(PostedListener::class.'@onPosted', $message);
    app(Dispatcher::class)->dispatch((new ExecuteHandlerJob($publication->id))->onQueue('ledger'));
    Event::listen(JobProcessing::class, static function (): void {
        Recorder::record('hydrated', Context::only(['message_id', 'correlation_id', 'causation_id', 'actor_id']));
    });

    runWorker('ledger');

    $onPosted = ledgerIds('onPosted');
    $acknowledge = ledgerIds('acknowledge');

    // The actor keys never travel in a job payload; the ids do.
    expect(ledgerRecord('hydrated'))->toBe([
        'message_id' => 'foreign-message',
        'correlation_id' => 'foreign-correlation',
        'causation_id' => 'foreign-cause',
    ])
        ->and($onPosted->headers())->toBe(['message_id' => 'event-message', 'correlation_id' => 'event-correlation', 'causation_id' => 'event-cause'])
        ->and($onPosted->context)->toBe($onPosted->headers())
        ->and($acknowledge->causationId)->toBe('event-message')
        ->and($acknowledge->correlationId)->toBe('event-correlation')
        ->and(ledgerRecord('acknowledge: actor_id'))->toBeNull();
});

test('a job whose handler is not in the registry fails on its first attempt with a LogicException naming it', function (): void {
    config(['logging.default' => 'null', 'laravents.async.tries' => 3]);
    $publication = EventPublication::record('Laravents\Tests\Fixtures\Async\Ledger\Handlers\Gone@handle', new Message(HandlerKind::Event, Posted::class, new Posted(5)));
    app(Dispatcher::class)->dispatch((new ExecuteHandlerJob($publication->id))->onQueue('ledger'));
    $attempts = countAttempts();

    runWorker('ledger');

    $exception = DB::table('failed_jobs')->sole()->exception;
    $this->assertIsString($exception);

    expect($exception)->toContain('LogicException: No handler [Laravents\Tests\Fixtures\Async\Ledger\Handlers\Gone@handle] is registered.')
        ->and($attempts())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Recorder::handlers())->toBe([])
        ->and($publication->refresh()->completed_at)->toBeNull();
});

test('a job whose publication row never existed fails on its first attempt with ModelNotFoundException', function (): void {
    config(['logging.default' => 'null', 'laravents.async.tries' => 3]);
    app(Dispatcher::class)->dispatch((new ExecuteHandlerJob('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->onQueue('ledger'));
    $attempts = countAttempts();

    runWorker('ledger');

    $exception = DB::table('failed_jobs')->sole()->exception;
    $this->assertIsString($exception);

    expect($exception)->toContain('No query results for model ['.EventPublication::class.'] 01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->and($attempts())->toBe(1)
        ->and(Recorder::handlers())->toBe([]);
});

test('a publication row deleted after the push fails its job on the first attempt with ModelNotFoundException', function (): void {
    config(['logging.default' => 'null', 'laravents.async.tries' => 3]);
    Laravents::publish(new Posted(5));
    $publication = EventPublication::query()->sole();
    $attempts = countAttempts();

    $publication->delete();
    runWorker('ledger');

    $exception = DB::table('failed_jobs')->sole()->exception;
    $this->assertIsString($exception);

    expect($exception)->toContain('No query results for model ['.EventPublication::class.'] '.$publication->id)
        ->and($attempts())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Recorder::handlers())->toBe([]);
});

test('Engine::replay() seeds Context from the headers for the handler and puts it back afterwards, also when the handler throws', function (): void {
    $registry = app(Registry::class);
    $acknowledge = $registry->handler(LedgerHandlers::class.'@acknowledge');
    $reject = $registry->handler(LedgerHandlers::class.'@reject');
    $this->assertNotNull($acknowledge);
    $this->assertNotNull($reject);

    $before = ['message_id' => 'outer-message', 'correlation_id' => 'outer-correlation', 'actor_id' => '7', 'tenant' => 'acme'];
    Context::add($before);
    $headers = ['message_id' => 'replayed-message', 'correlation_id' => 'replayed-correlation', 'causation_id' => 'replayed-cause'];

    app(Engine::class)->replay(new Message(HandlerKind::Command, 'ledger.acknowledge', new Acknowledge(1), $headers), $acknowledge);

    expect(ledgerIds('acknowledge')->context)->toBe($headers)
        ->and(ledgerRecord('acknowledge: actor_id'))->toBeNull()
        ->and(Context::all())->toEqual($before);

    expect(fn (): mixed => app(Engine::class)->replay(new Message(HandlerKind::Command, 'ledger.reject', new Reject, $headers), $reject))
        ->toThrow(RuntimeException::class, 'Rejected.')
        ->and(Context::all())->toEqual($before);
});
