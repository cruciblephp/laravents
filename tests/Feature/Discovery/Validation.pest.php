<?php

declare(strict_types=1);

use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Tests\Fixtures\Discovery\Invalid;
use Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners;
use Laravents\Tests\Fixtures\Discovery\Shop\Handlers\ShopHandlers;

/** @param list<class-string> $classes */
function buildFixtureRegistry(array $classes): void
{
    (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build($classes);
}

test('the valid fixtures build without errors', function (): void {
    buildFixtureRegistry([ShopHandlers::class, OrderListeners::class]);
})->throwsNoExceptions();

test('a duplicate command routing key throws and names both handlers', function (): void {
    expect(fn () => buildFixtureRegistry([ShopHandlers::class, Invalid\DuplicateCommandKey::class]))
        ->toThrow(DiscoveryException::class, 'Command routing key [shop.place_order] is handled by both '.ShopHandlers::class.'@place (');
});

test('a duplicate command error points at both files and lines', function (): void {
    try {
        buildFixtureRegistry([ShopHandlers::class, Invalid\DuplicateCommandKey::class]);
    } catch (DiscoveryException $e) {
        $first = new ReflectionMethod(ShopHandlers::class, 'place');
        $second = new ReflectionMethod(Invalid\DuplicateCommandKey::class, 'again');

        expect($e->getMessage())
            ->toContain($first->getFileName().':'.$first->getStartLine())
            ->toContain($second->getFileName().':'.$second->getStartLine());

        return;
    }

    throw new RuntimeException('Expected a DiscoveryException.');
});

test('a duplicate query routing key throws', function (): void {
    expect(fn () => buildFixtureRegistry([ShopHandlers::class, Invalid\DuplicateQueryKey::class]))
        ->toThrow(DiscoveryException::class, 'Query routing key [shop.find_order] is handled by both');
});

test('one message class with two command handlers throws', function (): void {
    expect(fn () => buildFixtureRegistry([ShopHandlers::class, Invalid\DuplicateMessageClass::class]))
        ->toThrow(DiscoveryException::class, 'Command message [Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder] is handled by both');
});

test('discovery rejects a handler that breaks a rule', function (string $class, string $message): void {
    /** @var class-string $class */
    expect(fn () => buildFixtureRegistry([$class]))->toThrow(DiscoveryException::class, $message);
})->with([
    '#[Authorize] on an event handler' => [Invalid\AuthorizedEventHandler::class, '#[Authorize] is not allowed on an event handler'],
    '#[Asynchronous] on a command handler' => [Invalid\AsynchronousCommandHandler::class, '#[Asynchronous] is only allowed on an event handler'],
    '#[Deferred] on a command handler' => [Invalid\DeferredCommandHandler::class, 'has #[Deferred], but #[Deferred] is only allowed on an event handler'],
    '#[Deferred] with #[Asynchronous]' => [Invalid\DeferredAsynchronousHandler::class, 'has both #[Deferred] and #[Asynchronous], but a handler runs in one mode: after the response or on a queue'],
    'two handler kinds on one method' => [Invalid\TwoKinds::class, 'has more than one of #[CommandHandler], #[QueryHandler] and #[EventHandler]'],
    'an unkeyed event handler without a class parameter' => [Invalid\UntypedEventHandler::class, 'needs a routing key or a class or interface as its first parameter'],
    'an unkeyed command handler without a class parameter' => [Invalid\UnkeyedArrayCommand::class, 'needs a routing key or a class or interface as its first parameter'],
    'an unkeyed command whose message is outside the root namespace' => [Invalid\OutsideRootCommand::class, 'cannot derive a routing key from [stdClass]'],
    'a static handler method' => [Invalid\StaticHandler::class, 'must be a public, non-static method'],
]);
