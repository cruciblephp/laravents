<?php

declare(strict_types=1);

use Laravents\Bus\PendingMessage;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Charge;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Charged;
use Laravents\Tests\Fixtures\Metadata\Billing\Handlers\BillingHandlers;
use Laravents\Tests\Fixtures\Metadata\Billing\Listeners\ReceiptListener;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Recorder::reset();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Metadata'))->build([
        BillingHandlers::class,
        ReceiptListener::class,
    ]));
});

function correlationRecordedBy(string $handler): string
{
    foreach (Recorder::calls() as [$name, $payload]) {
        if ($name === $handler && $payload instanceof Ids) {
            return $payload->correlationId;
        }
    }

    throw new RuntimeException(sprintf('%s recorded nothing.', $handler));
}

test('withHeaders()->command() passes the headers, by key and by object', function (): void {
    Laravents::withHeaders(['correlation_id' => 'req-1'])->command('billing.charge', ['amount' => 5]);
    $byKey = correlationRecordedBy('sendReceipt');
    Recorder::reset();

    Laravents::withHeaders(['correlation_id' => 'req-2'])->command(new Charge(5));

    expect($byKey)->toBe('req-1')
        ->and(correlationRecordedBy('sendReceipt'))->toBe('req-2');
});

test('withHeader()->query() passes the headers, chained one at a time', function (): void {
    expect(Laravents::withHeader('n', '7')->withHeader('flag', '1')->withHeader('ratio', '2')->query('billing.coerce'))
        ->toBe([7, true, 2.0, 'none', null]);
});

test('withHeader()->publish() passes the headers to event handlers', function (): void {
    Laravents::withHeader('correlation_id', 'req-3')->publish(new Charged(3));

    expect(correlationRecordedBy('onCharged'))->toBe('req-3');
});

test('the builder is immutable: each withHeader() returns a new pending message', function (): void {
    $base = Laravents::withHeaders(['n' => '1', 'flag' => '0', 'ratio' => '1']);
    $changed = $base->withHeader('n', '9');

    expect($changed)->toBeInstanceOf(PendingMessage::class)->not->toBe($base)
        ->and($base->query('billing.coerce'))->toBe([1, false, 1.0, 'none', null])
        ->and($changed->query('billing.coerce'))->toBe([9, false, 1.0, 'none', null]);
});

test('a reserved header through the builder still throws', function (): void {
    expect(fn () => Laravents::withHeader('message_id', 'x')->command('billing.charge', ['amount' => 5]))
        ->toThrow(InvalidArgumentException::class, 'Header [message_id] is set by Laravents and cannot be passed.')
        ->and(Recorder::calls())->toBe([]);
});

test('Laravel contextual attributes resolve in a handler, and #[Context] sees the same correlation_id as the header', function (): void {
    $this->actingAs(User::factory()->member()->make(['id' => 5]));

    expect(Laravents::withHeader('correlation_id', 'req-4')->query('billing.who_am_i'))->toBe([5, 'req-4', 'req-4']);
});
