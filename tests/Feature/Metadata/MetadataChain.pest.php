<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Metadata\Billing\Handlers\BillingHandlers;
use Laravents\Tests\Fixtures\Metadata\Billing\Listeners\ReceiptListener;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;

beforeEach(function (): void {
    Recorder::reset();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Metadata'))->build([
        BillingHandlers::class,
        ReceiptListener::class,
    ]));
});

/**
 * What each handler recorded, by handler name.
 *
 * @return array<string, mixed>
 */
function recorded(): array
{
    $byHandler = [];

    foreach (Recorder::calls() as [$handler, $payload]) {
        $byHandler[$handler] = $payload;
    }

    return $byHandler;
}

function idsOf(string $handler): Ids
{
    $ids = recorded()[$handler] ?? null;

    if (! $ids instanceof Ids) {
        throw new RuntimeException(sprintf('%s recorded no ids.', $handler));
    }

    return $ids;
}

test('a message outside any other message gets a fresh ULID and is its own correlation root', function (): void {
    $metadata = Laravents::query('billing.inspect');
    $this->assertIsArray($metadata);
    $this->assertIsString($metadata['message_id']);
    $this->assertIsString($metadata['timestamp']);

    expect(Str::isUlid($metadata['message_id']))->toBeTrue()
        ->and($metadata['correlation_id'])->toBe($metadata['message_id'])
        ->and($metadata['causation_id'])->toBeNull()
        ->and(DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $metadata['timestamp']))->toBeInstanceOf(DateTimeImmutable::class);
});

test('the timestamp header is Carbon\'s toJSON(): UTC with microseconds and Z, parsing back to the same instant', function (): void {
    $now = CarbonImmutable::parse('2026-10-16 10:00:00.123456', 'Europe/Madrid');
    Date::setTestNow($now);

    $metadata = Laravents::query('billing.inspect');
    $this->assertIsArray($metadata);
    $timestamp = $metadata['timestamp'];
    $this->assertIsString($timestamp);

    expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->toBe('2026-10-16T08:00:00.123456Z')
        ->and(CarbonImmutable::parse($timestamp)->format('U.u'))->toBe($now->format('U.u'));
});

test('a nested publish inherits correlation_id and its causation_id is the parent message_id', function (): void {
    Laravents::command('billing.charge', ['amount' => 5]);

    $command = idsOf('charge');
    $event = idsOf('onCharged');

    expect($event->correlationId)->toBe($command->correlationId)
        ->and($event->causationId)->toBe($command->messageId)
        ->and($event->messageId)->not->toBe($command->messageId);
});

test('A10: command, event and second command share one correlation_id and each causation_id points at its parent', function (): void {
    $returned = Laravents::command('billing.charge', ['amount' => 5]);

    $charge = idsOf('charge');
    $charged = idsOf('onCharged');
    $receipt = idsOf('sendReceipt');

    expect($returned)->toBe($charge->messageId)
        ->and([$charge->correlationId, $charged->correlationId, $receipt->correlationId])->each->toBe($charge->messageId)
        ->and($charge->causationId)->toBeNull()
        ->and($charged->causationId)->toBe($charge->messageId)
        ->and($receipt->causationId)->toBe($charged->messageId)
        ->and(array_unique([$charge->messageId, $charged->messageId, $receipt->messageId]))->toHaveCount(3);
});

test('inside a handler Context holds the same ids as the injected headers', function (): void {
    Laravents::command('billing.charge', ['amount' => 5]);

    foreach (['charge', 'onCharged', 'sendReceipt'] as $handler) {
        $ids = idsOf($handler);

        expect($ids->context)->toBe($ids->headers());
    }
});

test('Context is restored after a nested message and after the top-level one', function (): void {
    Context::add('request', 'r-1');

    Laravents::command('billing.charge', ['amount' => 5]);

    expect(recorded()['charge after publish'])->toBe(idsOf('charge')->messageId)
        ->and(Context::all())->toBe(['request' => 'r-1']);
});

test('Context is restored when a handler throws', function (): void {
    Context::add('request', 'r-1');

    expect(fn () => Laravents::command('billing.explode'))->toThrow(RuntimeException::class, 'Exploded.')
        ->and(Context::all())->toBe(['request' => 'r-1']);
});

test('a correlation_id passed by the caller is used for the whole chain', function (): void {
    Laravents::command('billing.charge', ['amount' => 5], ['correlation_id' => 'req-123']);

    expect([idsOf('charge')->correlationId, idsOf('onCharged')->correlationId, idsOf('sendReceipt')->correlationId])
        ->each->toBe('req-123')
        ->and(idsOf('charge')->causationId)->toBeNull();
});

test('a correlation_id already in Context, set by request middleware say, becomes the root', function (): void {
    Context::add('correlation_id', 'req-9');

    Laravents::command('billing.charge', ['amount' => 5]);

    expect(idsOf('sendReceipt')->correlationId)->toBe('req-9')
        ->and(Context::get('correlation_id'))->toBe('req-9');
});

test('headers Laravents sets cannot be passed by the caller', function (string $header): void {
    expect(fn () => Laravents::command('billing.charge', ['amount' => 5], [$header => 'x']))
        ->toThrow(InvalidArgumentException::class, sprintf('Header [%s] is set by Laravents and cannot be passed.', $header));

    expect(Recorder::calls())->toBe([]);
})->with(['message_id', 'causation_id', 'actor_id', 'actor_guard', 'timestamp']);
