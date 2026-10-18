<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerKind;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

test('the app Bus does not know about Laravents messages', function (): void {
    $message = new Message(HandlerKind::Command, 'band.give_nickname', new GiveNickname(2, 'The Quiet One'), []);

    expect(app(Dispatcher::class)->getCommandHandler($message))->toBeFalse();
});

test('faking the app Bus does not affect Laravents, because it owns a private dispatcher; only async handler jobs reach the app Bus', function (): void {
    Bus::fake();
    $this->actingAs(User::factory()->member()->create());

    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    expect(Nickname::query()->whereKey($id)->exists())->toBeTrue();
    Bus::assertDispatchedTimes(ExecuteHandlerJob::class, 1);
    Bus::assertNotDispatched(Message::class);
});
