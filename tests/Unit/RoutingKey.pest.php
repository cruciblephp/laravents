<?php

declare(strict_types=1);

use Laravents\Discovery\RoutingKey;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Handlers\BandHandlers;
use Workbench\App\Models\User;

test('a key is the module and the class name without the module word, in snake case', function (string $class, ?string $key): void {
    expect(RoutingKey::derive($class, 'Modules'))->toBe($key);
})->with([
    'command' => [GiveNickname::class, 'band.give_nickname'],
    'query' => [GetNickname::class, 'band.get_nickname'],
    'event' => [NicknameGiven::class, 'band.nickname_given'],
    'no module word' => ['Modules\Chat\Api\SendMessage', 'chat.send_message'],
    'multi-word module' => ['Modules\UserProfile\Api\UpdateUserProfile', 'user_profile.update'],
    'module word removed once' => ['Modules\Chat\Api\MergeChatIntoChat', 'chat.merge_into_chat'],
    'class is only the module word' => ['Modules\Chat\Api\Chat', 'chat.chat'],
    'outside the root namespace' => ['App\Jobs\GiveNickname', null],
    'directly in the root namespace' => ['Modules\GiveNickname', null],
]);

test('the module is the namespace segment under the root', function (): void {
    expect(RoutingKey::module(BandHandlers::class, 'Modules'))->toBe('Band')
        ->and(RoutingKey::module(PlaceOrder::class, 'Laravents\Tests\Fixtures\Discovery'))->toBe('Shop')
        ->and(RoutingKey::module(User::class, 'Modules'))->toBeNull();
});
