<?php

declare(strict_types=1);

use Laravents\Attributes\CommandHandler;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Handlers\BandHandlers;
use Modules\Press\Handlers\HeadlineOnNicknameGiven;

test('GiveNickname registers as band.give_nickname without a routing key string', function (): void {
    $attribute = (new ReflectionMethod(BandHandlers::class, 'give'))->getAttributes(CommandHandler::class)[0]->newInstance();
    $registry = app(Registry::class);

    expect($attribute->routingKey)->toBeNull()
        ->and($registry->commandHandler('band.give_nickname')?->handler())->toBe(BandHandlers::class.'@give')
        ->and($registry->routingKeyFor(GiveNickname::class, HandlerKind::Command))->toBe('band.give_nickname');
});

test('GetNickname registers as the band.get_nickname query', function (): void {
    expect(app(Registry::class)->queryHandler('band.get_nickname')?->messageClass)->toBe(GetNickname::class);
});

test('the Press module listens to NicknameGiven asynchronously', function (): void {
    $handlers = app(Registry::class)->eventHandlersFor(NicknameGiven::class);

    expect($handlers)->toHaveCount(1)
        ->and($handlers[0]->handler())->toBe(HeadlineOnNicknameGiven::class.'@__invoke')
        ->and($handlers[0]->module)->toBe('Press')
        ->and($handlers[0]->queue)->toBe('press');
});
