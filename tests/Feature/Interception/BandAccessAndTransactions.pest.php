<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\ActorMismatchException;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Interception\NicknameListeners\RecordTransactionLevel;
use Laravents\Tests\Fixtures\Interception\NicknameListeners\ThrowOnNicknameGiven;
use Laravents\Tests\Fixtures\Recorder;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Handlers\BandHandlers;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
});

/** @return list<TransactionBeginning> */
function transactionsBegun(Closure $call): array
{
    $begun = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$begun): void {
        $begun[] = $event;
    });

    try {
        $call();
    } finally {
        Event::forget(TransactionBeginning::class);
    }

    return $begun;
}

test('an allowed band.give_nickname runs in a transaction', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $begun = transactionsBegun(fn (): mixed => GiveNickname::send(memberId: 999, nickname: 'The Quiet One'));

    expect($begun)->toHaveCount(1)
        ->and(Nickname::query()->count())->toBe(1);
});

test('A6: when the Gate refuses band.give_nickname the handler never runs and no transaction begins', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $begun = transactionsBegun(function () use ($user): void {
        expect(fn (): mixed => GiveNickname::send(memberId: $user->id, nickname: 'The Quiet One'))->toThrow(AuthorizationException::class);
    });

    expect($begun)->toBe([])
        ->and(Nickname::query()->count())->toBe(0);
});

test('a fan is refused: Luciano may not give a nickname, and no transaction begins', function (): void {
    $luciano = User::factory()->create(['name' => 'Luciano']);
    $this->actingAs($luciano);

    $begun = transactionsBegun(function (): void {
        expect(fn (): mixed => GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toThrow(AuthorizationException::class);
    });

    expect($begun)->toBe([])
        ->and(Nickname::query()->count())->toBe(0);
});

test('a guest the Gate refuses gets an AuthenticationException, and no transaction begins', function (): void {
    $begun = transactionsBegun(function (): void {
        expect(fn (): mixed => GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toThrow(AuthenticationException::class);
    });

    expect($begun)->toBe([]);
});

test('an actor header the guard cannot vouch for is refused', function (): void {
    $this->actingAs(User::factory()->member()->create());
    Context::add(['actor_id' => '999999', 'actor_guard' => 'web']);

    expect(fn (): mixed => GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))
        ->toThrow(ActorMismatchException::class, 'The actor [web:999999] is not the user authenticated on that guard.');
});

test('actor headers naming a guard that holds nobody are refused, not taken for a guest', function (): void {
    Context::add(['actor_id' => '1', 'actor_guard' => 'web']);

    expect(fn (): mixed => GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))
        ->toThrow(ActorMismatchException::class, 'The actor [web:1] is not the user authenticated on that guard.')
        ->and(Nickname::query()->count())->toBe(0);
});

test('an actor_id without an actor_guard is no actor: the Gate is asked for a guest, not for the default guard\'s user', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);
    Context::add('actor_id', (string) $user->id);

    expect(fn (): mixed => GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toThrow(AuthenticationException::class)
        ->and(Nickname::query()->count())->toBe(0);
});

test('through HTTP an AuthorizationException is a 403 and a hydration error a 422', function (): void {
    Route::post('/_laravents/nickname', function (Request $request) {
        $payload = [];

        foreach ($request->all() as $field => $value) {
            if (is_string($field)) {
                $payload[$field] = $value;
            }
        }

        return response()->json(['id' => Laravents::command('band.give_nickname', $payload)], 201);
    });
    $user = User::factory()->member()->create();

    $this->actingAs($user)->postJson('/_laravents/nickname', ['memberId' => $user->id, 'nickname' => 'The Quiet One'])->assertForbidden();
    $this->actingAs($user)->postJson('/_laravents/nickname', [])->assertUnprocessable()->assertJsonValidationErrors('memberId');
    $this->actingAs($user)->postJson('/_laravents/nickname', ['memberId' => $user->id + 1, 'nickname' => 'The Quiet One'])->assertCreated();
});

test('A7: a sync listener runs inside the command transaction', function (): void {
    app()->instance(Registry::class, (new RegistryBuilder('Modules'))->build([BandHandlers::class, RecordTransactionLevel::class]));
    $this->actingAs(User::factory()->member()->create());
    $outside = DB::transactionLevel();

    GiveNickname::send(memberId: 999, nickname: 'The Quiet One');

    expect(Recorder::calls())->toBe([['listener transaction level', $outside + 1]]);
});

test('A7: when a sync listener throws, the nickname is rolled back and the exception reaches the caller', function (): void {
    app()->instance(Registry::class, (new RegistryBuilder('Modules'))->build([BandHandlers::class, ThrowOnNicknameGiven::class]));
    $this->actingAs(User::factory()->member()->create());

    expect(fn (): mixed => GiveNickname::send(memberId: 999, nickname: 'The Quiet One'))->toThrow(RuntimeException::class, 'Listener refused the nickname.')
        ->and(Nickname::query()->count())->toBe(0);
});
