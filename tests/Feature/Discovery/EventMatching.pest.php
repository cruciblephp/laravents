<?php

declare(strict_types=1);

use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\ExpressOrderPlaced;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;
use Laravents\Tests\Fixtures\Discovery\Shop\Handlers\ShopHandlers;

function fixtureRegistry(): Registry
{
    return (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build([ShopHandlers::class, OrderListeners::class]);
}

/** @param list<HandlerDefinition> $handlers
 * @return list<string> */
function methodsOf(array $handlers): array
{
    return array_map(fn (HandlerDefinition $handler): string => $handler->method, $handlers);
}

test('an unkeyed event handler matches its parameter type, its subclasses and implementors, in discovery order', function (): void {
    $registry = fixtureRegistry();

    expect(methodsOf($registry->eventHandlersFor(new OrderPlaced(1))))
        ->toBe(['onAnyOrderEvent', 'onPlaced', 'later', 'mail'])
        ->and(methodsOf($registry->eventHandlersFor(ExpressOrderPlaced::class)))
        ->toBe(['onAnyOrderEvent', 'onPlaced', 'onExpress', 'later', 'mail']);
});

test('an event nobody listens to has no handlers', function (): void {
    expect(fixtureRegistry()->eventHandlersFor(stdClass::class))->toBe([]);
});

test('a keyed event handler is found by its key only', function (): void {
    $registry = fixtureRegistry();

    expect(methodsOf($registry->eventHandlersForKey('shop.legacy_order_imported')))->toBe(['onLegacyImport'])
        ->and(methodsOf($registry->eventHandlersFor(OrderPlaced::class)))->not->toContain('onLegacyImport');
});

test('an asynchronous handler queues on its channel, or on its module when none is given', function (): void {
    $handlers = fixtureRegistry()->eventHandlersFor(OrderPlaced::class);
    $queues = array_combine(methodsOf($handlers), array_map(fn (HandlerDefinition $handler): ?string => $handler->queue, $handlers));

    expect($queues)->toBe(['onAnyOrderEvent' => null, 'onPlaced' => null, 'later' => 'listeners', 'mail' => 'mail']);
});

test('derived keys are registered for commands and queries', function (): void {
    $registry = fixtureRegistry();

    expect($registry->commandHandler('shop.place_order')?->handler())->toBe(ShopHandlers::class.'@place')
        ->and($registry->queryHandler('shop.find_order')?->handler())->toBe(ShopHandlers::class.'@find')
        ->and($registry->commandHandler('shop.find_order'))->toBeNull();
});
