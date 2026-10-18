<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Laravents\Bus\ContextMetadata;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Metadata\Billing\Handlers\BillingHandlers;
use Laravents\Tests\Fixtures\Metadata\Billing\Listeners\ReceiptListener;
use Laravents\Tests\Fixtures\Recorder;

beforeEach(function (): void {
    Recorder::reset();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Metadata'))->build([
        BillingHandlers::class,
        ReceiptListener::class,
    ]));
});

/** @return array<string, mixed> */
function metadataInContext(): array
{
    $values = [];

    foreach (ContextMetadata::KEYS as $key) {
        if (Context::has($key)) {
            $values[$key] = Context::get($key);
        }
    }

    return $values;
}

test('a key a handler adds to Context survives the dispatch, nested messages included', function (): void {
    Laravents::command('billing.tag', ['tenant' => 't-1']);

    expect(Context::get('tenant'))->toBe('t-1')
        ->and(metadataInContext())->toBe([]);
});

test('the five metadata keys are restored after a nested message', function (): void {
    $outer = ['message_id' => 'm-0', 'correlation_id' => 'c-0', 'causation_id' => 'p-0', 'actor_id' => '9', 'actor_guard' => 'api'];
    Context::add($outer);

    Laravents::command('billing.charge', ['amount' => 5]);

    expect(metadataInContext())->toEqual($outer)
        ->and(Recorder::calls())->not->toBe([]);
});

test('the five metadata keys are restored after a throw, and absent ones stay absent', function (): void {
    Context::add(['correlation_id' => 'c-0', 'tenant' => 't-0']);

    expect(fn () => Laravents::command('billing.explode'))->toThrow(RuntimeException::class, 'Exploded.')
        ->and(metadataInContext())->toBe(['correlation_id' => 'c-0'])
        ->and(Context::get('tenant'))->toBe('t-0');
});

test('a key a handler adds survives even when a later handler throws', function (): void {
    Laravents::command('billing.tag', ['tenant' => 't-2']);

    expect(fn () => Laravents::command('billing.explode'))->toThrow(RuntimeException::class)
        ->and(Context::get('tenant'))->toBe('t-2')
        ->and(metadataInContext())->toBe([]);
});
