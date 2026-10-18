<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Tests\Fixtures\Discovery\Invalid\ExposedEventHandler;

test('#[Expose] marks band.give_nickname and band.get_nickname, and nothing in Press or FanClub', function (): void {
    $registry = app(Registry::class);

    expect($registry->commandHandler('band.give_nickname')?->exposed)->toBeTrue()
        ->and($registry->queryHandler('band.get_nickname')?->exposed)->toBeTrue()
        ->and($registry->commandHandler('press.print_headline')?->exposed)->toBeFalse()
        ->and(array_filter($registry->all(), static fn (HandlerDefinition $handler): bool => in_array($handler->module, ['Press', 'FanClub'], true) && $handler->exposed))->toBe([]);
});

test('#[Expose] on an event handler fails discovery', function (): void {
    expect(fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build([ExposedEventHandler::class]))
        ->toThrow(DiscoveryException::class, ExposedEventHandler::class.'@on (');

    expect(fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build([ExposedEventHandler::class]))
        ->toThrow(DiscoveryException::class, 'has #[Expose], but #[Expose] is only allowed on a command or query handler: events are not requested.');
});

test('laravents:list has an HTTP column with the route of each exposed handler', function (): void {
    Artisan::call('laravents:list');

    $rows = array_values(array_filter(
        array_map(static fn (string $line): array => array_map(trim(...), explode('|', trim($line, " |\n"))), explode("\n", Artisan::output())),
        static fn (array $cells): bool => count($cells) === 7,
    ));

    expect(array_map(static fn (array $cells): array => [$cells[1], $cells[6]], $rows))->toBe([
        ['Key', 'HTTP'],
        ['band.give_nickname', 'POST /laravents/commands/band.give_nickname'],
        ['band.replace_member', '-'],
        ['band.get_nickname', 'GET /laravents/queries/band.get_nickname'],
        ['band.*', '-'],
        ['fan_club.welcome_fan', '-'],
        ['-', '-'],
        ['-', '-'],
        ['press.print_headline', '-'],
    ]);
});
