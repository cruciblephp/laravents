<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravents\Exceptions\UnknownRoutingKeyException;
use Laravents\Facades\Laravents;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameView;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->member()->create());
});

test('Laravents::command(band.give_nickname) gives the nickname and returns its id', function (): void {
    $id = Laravents::command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);
    $this->assertIsInt($id);

    $nickname = Nickname::query()->findOrFail($id);

    expect($nickname->given_by)->toBe(auth()->id())
        ->and($nickname->member_id)->toBe(2);
});

test('GiveNickname::send() gives the nickname and returns its id', function (): void {
    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    expect($id)->toBeInt()
        ->and(Nickname::query()->whereKey($id)->where('member_id', 2)->exists())->toBeTrue();
});

test('GetNickname::ask() returns a NicknameView with the right fields', function (): void {
    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    $view = GetNickname::ask(id: $id);
    $this->assertInstanceOf(NicknameView::class, $view);

    expect($view->id)->toBe($id)
        ->and($view->givenBy)->toBe(auth()->id())
        ->and($view->memberId)->toBe(2)
        ->and($view->givenAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('Laravents::query(band.get_nickname) returns the same view', function (): void {
    $id = GiveNickname::send(memberId: 3, nickname: 'The Quiet One');

    expect(Laravents::query('band.get_nickname', ['id' => $id]))->toEqual(GetNickname::ask(id: $id));
});

test('GetNickname::ask() for an unknown id throws ModelNotFoundException', function (): void {
    GetNickname::ask(id: 999);
})->throws(ModelNotFoundException::class);

test('Laravents::command() takes a command object and finds its key in the registry', function (): void {
    $id = Laravents::command(new GiveNickname(2, 'The Quiet One'));
    $this->assertIsInt($id);

    expect(Nickname::query()->whereKey($id)->where('member_id', 2)->exists())->toBeTrue();
});

test('Laravents::query() takes a query object and finds its key in the registry', function (): void {
    $id = GiveNickname::send(memberId: 4, nickname: 'The Quiet One');
    $this->assertIsInt($id);

    expect(Laravents::query(new GetNickname($id)))->toEqual(GetNickname::ask(id: $id));
});

test('a message object with a second payload is refused, since that is most likely misplaced headers', function (): void {
    Laravents::command(new GiveNickname(2, 'The Quiet One'), ['correlation_id' => 'x']);
})->throws(InvalidArgumentException::class, GiveNickname::class.' is already the payload; pass headers as headers: [...].');

test('Laravents::command() accepts an object payload', function (): void {
    $id = Laravents::command('band.give_nickname', new GiveNickname(2, 'The Quiet One'));

    expect(Nickname::query()->whereKey($id)->exists())->toBeTrue();
});

test('missing payload fields throw a ValidationException naming each', function (): void {
    $e = caught(ValidationException::class, fn () => Laravents::command('band.give_nickname', []));

    expect(array_keys($e->errors()))->toBe(['memberId', 'nickname'])
        ->and($e->getMessage())->toBe('The memberId field is required. (and 1 more error)');
});

test('a mistyped payload field throws a ValidationException naming memberId', function (): void {
    $e = caught(ValidationException::class, fn () => Laravents::command('band.give_nickname', ['memberId' => 'x', 'nickname' => 'The Quiet One']));

    expect(array_keys($e->errors()))->toBe(['memberId'])
        ->and($e->getMessage())->toBe('The memberId field must be of type int.');
});

test('an unknown key throws UnknownRoutingKeyException, and a command key is not a query key', function (Closure $call, string $message): void {
    expect($call)->toThrow(UnknownRoutingKeyException::class, $message);
})->with([
    'unknown command' => [fn () => Laravents::command('chat.close'), 'Unknown command routing key [chat.close].'],
    'unknown query' => [fn () => Laravents::query('chat.list'), 'Unknown query routing key [chat.list].'],
    'command key on the query bus' => [fn () => Laravents::query('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']), 'Unknown query routing key [band.give_nickname].'],
]);

test('a mistyped key suggests the registered keys of its module, closest first', function (): void {
    expect(caught(UnknownRoutingKeyException::class, fn () => Laravents::command('band.give_nickame'))->getMessage())
        ->toBe('Unknown command routing key [band.give_nickame]. Did you mean one of these? [band.give_nickname], [band.replace_member]');
});

test('a query key is not suggested for a command, nor a command key for a query', function (): void {
    expect(caught(UnknownRoutingKeyException::class, fn () => Laravents::command('band.get_nickname'))->getMessage())
        ->not->toContain('[band.get_nickname]?')
        ->and(caught(UnknownRoutingKeyException::class, fn () => Laravents::query('band.give_nickame'))->getMessage())
        ->not->toContain('[band.give_nickname]');
});

test('a key with nothing close has no suggestion', function (): void {
    expect(caught(UnknownRoutingKeyException::class, fn () => Laravents::command('billing.refund'))->getMessage())
        ->toBe('Unknown command routing key [billing.refund].');
});

test('band.give_nickname takes the actor through #[CurrentUser]: the nickname is given by the acting member', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $id = GiveNickname::send(memberId: $user->id + 1, nickname: 'The Quiet One');
    $this->assertIsInt($id);

    expect(Nickname::query()->findOrFail($id)->given_by)->toBe($user->id);
});
