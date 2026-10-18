<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravents\Contracts\CommandBus;
use Laravents\Facades\Laravents;
use Laravents\Testing\LaraventsFake;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/*
 * The fake lives in the container and the facade's resolved instance, both of which Laravel's TestCase
 * replaces per test. Each test starts by proving it sees the real buses, so whichever runs second proves
 * the other's fake is gone, in any order.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->member()->create());

    expect(Laravents::isFake())->toBeFalse()
        ->and(app(CommandBus::class))->not->toBeInstanceOf(LaraventsFake::class);
});

test('a test that fakes', function (): void {
    Laravents::fake();

    expect(GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toBeNull()
        ->and(Nickname::query()->count())->toBe(0);
});

test('a test that sends for real, through the class and the facade, and writes rows', function (): void {
    $first = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $second = Laravents::command('band.give_nickname', ['memberId' => 3, 'nickname' => 'The Quiet One']);

    expect(Nickname::query()->pluck('id')->all())->toBe([$first, $second]);
});

test('a test that fakes again, through the facade only', function (): void {
    Laravents::fake();

    Laravents::command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);

    expect(Nickname::query()->count())->toBe(0);
    Laravents::assertSent('band.give_nickname');
});
