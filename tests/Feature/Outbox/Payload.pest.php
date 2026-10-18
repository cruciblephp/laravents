<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\InvalidPayloadException;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Attach;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Attached;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Receipted;
use Laravents\Tests\Fixtures\Async\Ledger\Entries;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\AttachHandlers;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\PayloadListener;
use Laravents\Tests\Fixtures\Recorder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
    Entries::createTable();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Async'))->build([
        AttachHandlers::class,
        PayloadListener::class,
    ]));
});

/** The event_payload column as the database holds it, read past the model. */
function rawPayload(): string
{
    $payload = DB::table('laravents_event_publications')->sole()->event_payload;

    return is_string($payload) ? $payload : throw new RuntimeException('event_payload is not a string.');
}

test('an event with private and protected properties is stored without NUL bytes, as valid UTF-8 JSON, and the worker gets both properties back', function (): void {
    $event = new Receipted(12, 'paid in full');

    // The fixture proves what it claims: its plain serialize() output holds NUL bytes.
    expect(serialize($event))->toContain("\0");

    Laravents::publish($event);

    $raw = rawPayload();

    expect($raw)->not->toContain("\0")
        ->and(mb_check_encoding($raw, 'UTF-8'))->toBeTrue()
        ->and(json_decode($raw))->toBeString()
        ->and(json_decode($raw))->toBe(serialize($event));

    runWorker('ledger');

    expect(Recorder::calls())->toContain(['onReceipted', [12, 'paid in full']])
        ->and(EventPublication::query()->sole()->completed_at)->not->toBeNull();
});

test('a payload that is not valid UTF-8 throws InvalidPayloadException inside the publisher\'s transaction: no row, no job, the command\'s data rolled back', function (): void {
    expect(fn (): mixed => Laravents::command(new Attach("\xff\xfe")))->toThrow(
        InvalidPayloadException::class,
        'Unable to JSON encode the payload of event ['.Attached::class.'] for listener ['.PayloadListener::class.'@onAttached]: Malformed UTF-8 characters, possibly incorrectly encoded. A binary property must be encoded by the event (base64) before publishing',
    );

    expect(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Entries::count())->toBe(0);

    runWorker('ledger');

    expect(Recorder::handlers())->toBe([]);
});

test('a row in the old raw serialize() format is corrupt: message() throws and the job fails on its first attempt', function (): void {
    config(['logging.default' => 'null', 'laravents.async.tries' => 3]);
    Laravents::publish(new Receipted(3, 'old'));
    DB::table('laravents_event_publications')->update(['event_payload' => serialize(new Receipted(3, 'old'))]);
    $publication = EventPublication::query()->sole();
    $attempts = countAttempts();

    expect(fn (): mixed => $publication->message())
        ->toThrow(LogicException::class, 'The payload of publication ['.$publication->id.'] is not a JSON string holding a serialized object or an array keyed by field.');

    runWorker('ledger');

    $exception = DB::table('failed_jobs')->sole()->exception;
    $this->assertIsString($exception);

    expect($exception)->toContain('LogicException: The payload of publication ['.$publication->id.']')
        ->and($attempts())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Recorder::handlers())->toBe([])
        ->and($publication->refresh()->completed_at)->toBeNull();
});

test('a keyed event round-trips the same way: the column holds a JSON string, the worker gets the array', function (): void {
    Laravents::publish('ledger.imported', ['source' => 'csv', 'rows' => 4]);

    $raw = rawPayload();

    expect(json_decode($raw))->toBe(serialize(['source' => 'csv', 'rows' => 4]))
        ->and(EventPublication::query()->sole()->event_class)->toBeNull();

    runWorker('ledger');

    expect(Recorder::calls())->toContain(['imported', ['source' => 'csv', 'rows' => 4]])
        ->and(EventPublication::query()->sole()->completed_at)->not->toBeNull();
});
