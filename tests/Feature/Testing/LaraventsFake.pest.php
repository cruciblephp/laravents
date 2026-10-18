<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravents\Bus\Gateway;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\EventBus;
use Laravents\Contracts\QueryBus;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Testing\LaraventsFake;
use Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners;
use Laravents\Tests\Fixtures\Recorder;
use Laravents\Tests\Fixtures\Testing\Billing\Api\InvoicePaid;
use Laravents\Tests\Fixtures\Testing\Billing\Handlers\InvoiceListeners;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Api\NicknameView;
use Modules\Band\Models\Nickname;
use Modules\Press\Api\PrintHeadline;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->member()->create());
});

test('1: a full fake records GiveNickname::send() and runs no handler', function (): void {
    Laravents::fake();

    expect(GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toBeNull()
        ->and(Nickname::query()->count())->toBe(0);

    Laravents::assertSent(GiveNickname::class, fn (GiveNickname $command): bool => $command->memberId === 2);
    Laravents::assertNothingPublished();
    Laravents::assertNothingAsked();
});

test('2: a command sent by key is recorded as its hydrated object, and answers to its class and its key', function (): void {
    $fake = Laravents::fake();

    Laravents::command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);

    $command = $fake->sent('band.give_nickname')->sole();

    expect($command)->toBeInstanceOf(GiveNickname::class)
        ->and($command)->toEqual(new GiveNickname(2, 'The Quiet One'))
        ->and($fake->sent(GiveNickname::class)->sole())->toBe($command);

    $fake->assertSent(GiveNickname::class, fn (GiveNickname $command): bool => $command->memberId === 2);
    $fake->assertSent('band.give_nickname');
});

test('3: fake([NicknameGiven]) runs band.give_nickname for real and records the event; its async handler is not reached, so no outbox row and no job', function (): void {
    $fake = Laravents::fake([NicknameGiven::class]);

    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $this->assertIsInt($id);

    expect(Nickname::query()->whereKey($id)->exists())->toBeTrue()
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    $fake->assertPublished(NicknameGiven::class, fn (NicknameGiven $event): bool => $event->nicknameId === $id && $event->memberId === 2);
    $fake->assertPublishedTimes(NicknameGiven::class, 1);
    $fake->assertNotSent(GiveNickname::class);
});

test('4: except([GiveNickname]) runs the command and fakes everything else', function (): void {
    $fake = Laravents::fake()->except([GiveNickname::class]);

    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $this->assertIsInt($id);

    expect(Nickname::query()->whereKey($id)->exists())->toBeTrue()
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    $fake->assertNothingSent();
    $fake->assertPublished(NicknameGiven::class);
});

test('5: returns() and answers() give faked commands and queries their result', function (): void {
    Laravents::fake()
        ->returns(GiveNickname::class, 42)
        ->answers(GetNickname::class, fn (GetNickname $query): NicknameView => new NicknameView($query->id, 1, 2, 'The Quiet One', new CarbonImmutable('2026-10-15 12:00:00')));

    expect(GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toBe(42);

    $view = GetNickname::ask(id: 42);
    $this->assertInstanceOf(NicknameView::class, $view);

    expect($view->id)->toBe(42)
        ->and($view->memberId)->toBe(2);

    Laravents::assertAsked(GetNickname::class, fn (GetNickname $query): bool => $query->id === 42);
});

test('6: headers reach the callback', function (): void {
    Laravents::fake();

    Laravents::withHeader('tenant', 'acme')->publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));

    Laravents::assertPublished(NicknameGiven::class, fn (NicknameGiven $event, array $headers): bool => $headers['tenant'] === 'acme');
    Laravents::assertNotPublished(NicknameGiven::class, fn (NicknameGiven $event, array $headers): bool => $headers['tenant'] === 'other');
});

test('7: each failing assertion throws AssertionFailedError naming the message', function (Closure $assertion, string $message): void {
    $fake = Laravents::fake();

    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    GetNickname::ask(id: 1);
    NicknameGiven::publish(1, 1, 2, 'The Quiet One');

    expect(fn () => $assertion($fake))->toThrow(AssertionFailedError::class, $message);
})->with([
    'assertNotSent' => [fn (LaraventsFake $fake) => $fake->assertNotSent(GiveNickname::class), 'The unexpected ['.GiveNickname::class.'] command was sent.'],
    'assertNothingSent' => [fn (LaraventsFake $fake) => $fake->assertNothingSent(), "1 unexpected commands were sent:\n\n- ".GiveNickname::class.' sent 1 time'],
    'assertNotAsked' => [fn (LaraventsFake $fake) => $fake->assertNotAsked(GetNickname::class), 'The unexpected ['.GetNickname::class.'] query was asked.'],
    'assertNothingAsked' => [fn (LaraventsFake $fake) => $fake->assertNothingAsked(), "1 unexpected queries were asked:\n\n- ".GetNickname::class.' asked 1 time'],
    'assertNotPublished' => [fn (LaraventsFake $fake) => $fake->assertNotPublished(NicknameGiven::class), 'The unexpected ['.NicknameGiven::class.'] event was published.'],
    'assertNothingPublished' => [fn (LaraventsFake $fake) => $fake->assertNothingPublished(), "1 unexpected events were published:\n\n- ".NicknameGiven::class.' published 1 time'],
    'assertSent' => [fn (LaraventsFake $fake) => $fake->assertSent(PrintHeadline::class), 'The expected ['.PrintHeadline::class.'] command was not sent.'],
    'assertSent with a callback' => [fn (LaraventsFake $fake) => $fake->assertSent(GiveNickname::class, fn (GiveNickname $command): bool => $command->memberId === 3), 'The expected ['.GiveNickname::class.'] command was not sent.'],
    'assertSentTimes' => [fn (LaraventsFake $fake) => $fake->assertSentTimes(GiveNickname::class, 2), 'The expected ['.GiveNickname::class.'] command was sent 1 time instead of 2 times.'],
    'assertAsked' => [fn (LaraventsFake $fake) => $fake->assertAsked('chat.unknown'), 'The expected [chat.unknown] query was not asked.'],
    'assertAskedTimes' => [fn (LaraventsFake $fake) => $fake->assertAsked(GetNickname::class, 3), 'The expected ['.GetNickname::class.'] query was asked 1 time instead of 3 times.'],
    'assertPublished' => [fn (LaraventsFake $fake) => $fake->assertPublished(NicknameGiven::class, fn (NicknameGiven $event): bool => $event->nicknameId === 9), 'The expected ['.NicknameGiven::class.'] event was not published.'],
    'assertPublishedTimes' => [fn (LaraventsFake $fake) => $fake->assertPublishedTimes(NicknameGiven::class, 0), 'The expected ['.NicknameGiven::class.'] event was published 1 time instead of 0 times.'],
]);

test('fake() binds the three contracts and swaps the facade root for a Gateway built on the fake', function (): void {
    $before = Laravents::getFacadeRoot();
    $fake = Laravents::fake();

    expect(app(CommandBus::class))->toBe($fake)
        ->and(app(QueryBus::class))->toBe($fake)
        ->and(app(EventBus::class))->toBe($fake)
        ->and(Laravents::getFacadeRoot())->toBeInstanceOf(Gateway::class)
        ->and(Laravents::getFacadeRoot())->not->toBe($before)
        ->and(app(Gateway::class))->toBe(Laravents::getFacadeRoot())
        ->and(Laravents::isFake())->toBeTrue();
});

test('a facade that resolved its Gateway before fake() reaches the fake afterwards', function (): void {
    $real = Laravents::command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);

    $fake = Laravents::fake();
    Laravents::command('band.give_nickname', ['memberId' => 3, 'nickname' => 'The Quiet One']);

    expect(Nickname::query()->pluck('id')->all())->toBe([$real]);
    $fake->assertSent(GiveNickname::class, fn (GiveNickname $command): bool => $command->memberId === 3);
});

test('faked by key, sent as an object; faked by class, sent by key', function (): void {
    $fake = Laravents::fake(['band.give_nickname', GetNickname::class]);

    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    Laravents::query('band.get_nickname', ['id' => 5]);

    expect(Nickname::query()->count())->toBe(0);
    $fake->assertSent(GiveNickname::class);
    $fake->assertSent('band.give_nickname');
    $fake->assertAsked(GetNickname::class, fn (GetNickname $query): bool => $query->id === 5);
    $fake->assertAsked('band.get_nickname', 1);
});

test('a message that is not faked passes through to the real bus with its headers', function (): void {
    $fake = Laravents::fake([GetNickname::class]);

    $id = Laravents::withHeader('correlation_id', 'req-1')->command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);
    $this->assertIsInt($id);

    // The real band.give_nickname ran with the header: its async listener's outbox row carries the correlation id.
    expect(EventPublication::query()->sole()->correlation_id)->toBe('req-1');
    $fake->assertNothingSent();
    $fake->assertNothingPublished();
});

test('a handler that runs for real reaches the fake through its injected bus, and its result comes back', function (): void {
    $fake = Laravents::fake([NicknameGiven::class]);

    $id = Laravents::command(new GiveNickname(2, 'The Quiet One'));

    expect($id)->toBe(Nickname::query()->sole()->id);
    $fake->assertPublished(NicknameGiven::class);
});

test('an unfaked return is null, and returns() keyed by routing key works for a command sent as an object', function (): void {
    Laravents::fake()->returns('band.give_nickname', fn (GiveNickname $command, array $headers): array => [$command->memberId, $headers['tenant']]);

    expect(Laravents::withHeader('tenant', 'acme')->command(new GiveNickname(7, 'The Quiet One')))->toBe([7, 'acme'])
        ->and(GetNickname::ask(id: 1))->toBeNull()
        ->and(Laravents::command('press.print_headline', ['nicknameId' => 1, 'memberId' => 2, 'publicationId' => 'p']))->toBeNull();
});

test('an int callback asserts the number of times, as Event::assertDispatched() does', function (): void {
    $fake = Laravents::fake();

    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    GiveNickname::send(memberId: 3, nickname: 'The Quiet One');
    NicknameGiven::publish(1, 1, 2, 'The Quiet One');

    $fake->assertSent(GiveNickname::class, 2);
    $fake->assertSentTimes('band.give_nickname', 2);
    $fake->assertPublished(NicknameGiven::class, 1);
    expect($fake->sent(GiveNickname::class, fn (GiveNickname $command): bool => $command->memberId === 3))->toHaveCount(1);
});

test('a closure as the first argument names the class by its parameter type, as Laravel\'s fakes do', function (): void {
    $fake = Laravents::fake();

    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    $fake->assertSent(fn (GiveNickname $command): bool => $command->memberId === 2);
    $fake->assertNotSent(fn (GiveNickname $command): bool => $command->memberId === 3);
    $fake->assertSentTimes(fn (GiveNickname $command): bool => $command->memberId === 2, 1);
});

test('a closure in fake() decides by the hydrated message and its headers', function (): void {
    $fake = Laravents::fake([fn (object|array $message, array $headers): bool => $message instanceof GiveNickname && $message->memberId === 2]);

    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $id = GiveNickname::send(memberId: 3, nickname: 'The Quiet One');

    expect(Nickname::query()->sole()->id)->toBe($id);
    $fake->assertSent(GiveNickname::class, 1);
});

test('an event published by key is hydrated when its keyed handlers take a class, and kept as an array when they do not', function (): void {
    Recorder::reset();
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures'))->build([InvoiceListeners::class, OrderListeners::class]));
    $fake = Laravents::fake();

    Laravents::publish('billing.invoice_paid', ['invoiceId' => 7]);
    Laravents::publish('shop.legacy_order_imported', ['id' => 1]);

    expect($fake->published('billing.invoice_paid')->sole())->toEqual(new InvoicePaid(7))
        ->and($fake->published(InvoicePaid::class))->toHaveCount(1)
        ->and($fake->published('shop.legacy_order_imported')->sole())->toBe(['id' => 1])
        ->and(Recorder::calls())->toBe([]);
    $fake->assertPublished('shop.legacy_order_imported', fn (array $payload): bool => $payload['id'] === 1);
});

test('an event key that is not faked reaches its real handlers', function (): void {
    Recorder::reset();
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures'))->build([InvoiceListeners::class, OrderListeners::class]));
    $fake = Laravents::fake(['shop.legacy_order_imported']);

    Laravents::publish('billing.invoice_paid', ['invoiceId' => 7]);
    Laravents::publish('shop.legacy_order_imported', ['id' => 1]);

    expect(Recorder::calls())->toEqual([['onPaid', new InvoicePaid(7)]]);
    $fake->assertNotPublished(InvoicePaid::class);
    $fake->assertPublished('shop.legacy_order_imported');
});

test('an unknown routing key still throws, with its suggestion, since the fake stands in for handlers and not for the registry', function (): void {
    Laravents::fake();

    Laravents::command('band.give_nickame', ['memberId' => 2, 'nickname' => 'The Quiet One']);
})->throws(InvalidArgumentException::class, 'Unknown command routing key [band.give_nickame]. Did you mean one of these? [band.give_nickname], [band.replace_member]');

test('an object no handler takes still throws', function (): void {
    Laravents::fake();

    Laravents::command(new NicknameGiven(1, 1, 2, 'The Quiet One'));
})->throws(InvalidArgumentException::class, 'No command or query handler for ['.NicknameGiven::class.'].');

test('a faked message still refuses a header only Laravents may set', function (): void {
    Laravents::fake();

    Laravents::withHeader('message_id', 'x')->command(new GiveNickname(2, 'The Quiet One'));
})->throws(InvalidArgumentException::class, 'Header [message_id] is set by Laravents and cannot be passed.');

test('faking twice wraps the real buses, not the first fake, and starts a fresh record', function (): void {
    $first = Laravents::fake();
    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    $second = Laravents::fake([GetNickname::class]);
    $id = GiveNickname::send(memberId: 3, nickname: 'The Quiet One');

    expect($second->commandBus)->not->toBeInstanceOf(LaraventsFake::class)
        ->and($second->commandBus)->toBe($first->commandBus)
        ->and($id)->toBe(Nickname::query()->sole()->id);
    $second->assertNothingSent();
});

test('the facade\'s assertions need fake() first', function (): void {
    Laravents::assertNothingSent();
})->throws(BadMethodCallException::class, 'Laravents::assertNothingSent() needs Laravents::fake() first.');
