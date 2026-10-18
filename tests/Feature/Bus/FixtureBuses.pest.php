<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\EventBus;
use Laravents\Contracts\QueryBus;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\UnknownRoutingKeyException;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Discovery\Listeners\FailingListeners;
use Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\ExpressOrderPlaced;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\FindOrder;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderArchived;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderCancelled;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;
use Laravents\Tests\Fixtures\Discovery\Shop\Handlers\ShopHandlers;
use Laravents\Tests\Fixtures\Recorder;

// Async handlers write outbox rows; the queue is faked so no worker is involved.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
    Queue::fake();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build([
        ShopHandlers::class,
        OrderListeners::class,
        FailingListeners::class,
    ]));
});

test('a command returns its handler result, by key and by object', function (): void {
    expect(Laravents::command('shop.place_order', ['sku' => 'A-1']))->toBe('A-1')
        ->and(Laravents::query('shop.find_order', ['id' => 7]))->toBe(7)
        ->and(app(QueryBus::class)->dispatch(new FindOrder(8)))->toBe(8);
});

test('dispatching an object with no handler throws UnknownRoutingKeyException', function (): void {
    app(CommandBus::class)->dispatch(new OrderPlaced(1));
})->throws(UnknownRoutingKeyException::class, 'No command handler for ['.OrderPlaced::class.'].');

test('a DomainException thrown in a handler reaches the caller unchanged', function (): void {
    $e = caught(Throwable::class, fn () => Laravents::command('shop.decline_order', ['sku' => 'A-1']));

    expect($e::class)->toBe(DomainException::class)
        ->and($e->getMessage())->toBe('Card declined.')
        ->and($e->getPrevious())->toBeNull();
});

test('publishing an event with no handlers is a no-op', function (): void {
    Laravents::publish(new OrderArchived(1));

    expect(Recorder::calls())->toBe([]);
});

test('sync event handlers run inline, in discovery order', function (): void {
    $event = new ExpressOrderPlaced(7);

    Laravents::publish($event);

    expect(Recorder::handlers())->toBe(['onAnyOrderEvent', 'onPlaced', 'onExpress'])
        ->and(array_column(Recorder::calls(), 1))->each->toBe($event);
});

test('an event handler exception propagates to the publisher', function (): void {
    Laravents::publish(new OrderCancelled(1));
})->throws(RuntimeException::class, 'Listener failed.');

test('an #[Asynchronous] handler is queued by publish(), not invoked inline', function (): void {
    Laravents::publish(new OrderPlaced(1));

    expect(Recorder::handlers())->toBe(['onAnyOrderEvent', 'onPlaced'])
        ->not->toContain('later')
        ->not->toContain('mail');

    $queue = Queue::getFacadeRoot();
    $this->assertInstanceOf(QueueFake::class, $queue);

    $queued = [];

    foreach ($queue->pushed(ExecuteHandlerJob::class) as $job) {
        $this->assertInstanceOf(ExecuteHandlerJob::class, $job);
        $queued[] = [publicationOf($job)->listener, $job->queue];
    }

    expect($queued)->toBe([
        [OrderListeners::class.'@later', 'listeners'],
        [OrderListeners::class.'@mail', 'mail'],
    ]);
});

test('Laravents::publish() with an object reaches the handlers that match its type', function (): void {
    Laravents::publish(new OrderPlaced(3), headers: ['correlation_id' => 'c-1']);

    expect(Recorder::handlers())->toBe(['onAnyOrderEvent', 'onPlaced']);
});

test('Laravents::publish() with a key reaches the keyed handler with the array payload, and no typed handler', function (): void {
    Laravents::publish('shop.legacy_order_imported', ['legacyId' => 5]);

    expect(Recorder::calls())->toBe([['onLegacyImport', ['legacyId' => 5]]]);
});

test('EventBus::publishTo() reaches the same keyed handler', function (): void {
    app(EventBus::class)->publishTo('shop.legacy_order_imported', ['legacyId' => 6]);

    expect(Recorder::calls())->toBe([['onLegacyImport', ['legacyId' => 6]]]);
});

test('publishing to a key nobody listens to is a no-op', function (): void {
    Laravents::publish('shop.nobody', ['x' => 1]);

    expect(Recorder::calls())->toBe([]);
});
