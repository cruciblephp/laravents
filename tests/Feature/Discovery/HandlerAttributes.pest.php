<?php

declare(strict_types=1);

use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Tests\Fixtures\HandlerAttributes\Label;
use Laravents\Tests\Fixtures\HandlerAttributes\LoudLabel;
use Laravents\Tests\Fixtures\HandlerAttributes\Shelf\Handlers\ShelfHandlers;
use Laravents\Tests\Fixtures\HandlerAttributes\Unused;

function shelfRegistry(): Registry
{
    return (new RegistryBuilder('Laravents\Tests\Fixtures\HandlerAttributes'))->build([ShelfHandlers::class]);
}

function shelfQuery(string $key): HandlerDefinition
{
    return shelfRegistry()->queryHandler($key) ?? throw new LogicException("no {$key}");
}

test('exposed() is the #[Expose]d handlers of one kind by key, sorted, and none for events', function (): void {
    $registry = shelfRegistry();

    expect(array_keys($registry->exposed(HandlerKind::Query)))->toBe(['shelf.labelled', 'shelf.plain'])
        ->and($registry->exposed(HandlerKind::Query)['shelf.plain']->handler())->toBe(ShelfHandlers::class.'@plain')
        ->and($registry->exposed(HandlerKind::Command))->toBe([])
        ->and($registry->exposed(HandlerKind::Event))->toBe([]);
});

test('exposed() reads the workbench: one command, one query, and no event though a command is exposed', function (): void {
    expect(array_keys(app(Registry::class)->exposed(HandlerKind::Command)))->toBe(['band.give_nickname'])
        ->and(array_keys(app(Registry::class)->exposed(HandlerKind::Query)))->toBe(['band.get_nickname'])
        ->and(app(Registry::class)->exposed(HandlerKind::Event))->toBe([]);
});

test('attribute() is the method\'s, else the class\'s, of the class asked or a subclass of it', function (): void {
    expect(shelfQuery('shelf.labelled')->attribute(Label::class))->toEqual(new Label('method'))
        ->and(shelfQuery('shelf.labelled')->attribute(LoudLabel::class))->toEqual(new LoudLabel('loud'))
        ->and(shelfQuery('shelf.plain')->attribute(Label::class))->toEqual(new Label('class'))
        ->and(shelfQuery('shelf.plain')->attribute(LoudLabel::class))->toBeNull()
        ->and(shelfQuery('shelf.plain')->attribute(Unused::class))->toBeNull();
});

test('attributes() is every one, the method\'s in their order, then the class\'s', function (): void {
    expect(shelfQuery('shelf.labelled')->attributes(Label::class))->toEqual([new Label('method'), new LoudLabel('loud'), new Label('class')])
        ->and(shelfQuery('shelf.hidden')->attributes(Label::class))->toEqual([new Label('class')])
        ->and(shelfQuery('shelf.hidden')->attributes(Unused::class))->toBe([]);
});
