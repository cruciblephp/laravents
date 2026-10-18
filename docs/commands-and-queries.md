# Commands and Queries

## Commands, queries and their handlers

A command asks a module to do something and has exactly one handler; it may return a result (the id of what it created, say). A query asks a module for data, has exactly one handler, and changes nothing. Both are plain PHP objects, usually `final readonly` with promoted constructor properties, in the module's `Api` namespace.

```php
final readonly class GiveNickname implements ValidatesPayload
{
    use Sendable;

    public function __construct(public int $memberId, public string $nickname) {}

    public static function rules(): array
    {
        return [
            'memberId' => 'required|integer|min:1',
            'nickname' => 'required|string|max:40',
        ];
    }
}
```

From [`workbench/modules/Band/Api/GiveNickname.php`](../workbench/modules/Band/Api/GiveNickname.php).

```php
final readonly class GetNickname
{
    use Askable;

    public function __construct(public int $id) {}
}
```

From [`workbench/modules/Band/Api/GetNickname.php`](../workbench/modules/Band/Api/GetNickname.php).

The handler is a public method with `#[CommandHandler]` or `#[QueryHandler]` whose first parameter is the message. Further parameters are resolved by the container, as in a controller: services, Laravel's contextual attributes (`#[CurrentUser]`, `#[Config]`, `#[Context]`, ...) and message headers through `#[Header]` ([metadata and the actor](metadata-and-actor.md#headers-in-handlers)).

```php
final class BandHandlers
{
    #[CommandHandler]
    #[Authorize]
    #[Transactional]
    #[Expose]
    public function give(GiveNickname $command, #[CurrentUser] Authenticatable $actor): int
    {
        return Nickname::query()->create([
            'given_by' => $actor->getAuthIdentifier(),
            'member_id' => $command->memberId,
            'nickname' => $command->nickname,
        ])->id;
    }

    #[QueryHandler]
    #[Expose]
    public function get(GetNickname $query): NicknameView
    {
        $nickname = Nickname::query()->findOrFail($query->id);

        return new NicknameView($nickname->id, $nickname->given_by, $nickname->member_id, $nickname->nickname, $nickname->created_at->toImmutable());
    }
}
```

From [`workbench/modules/Band/Handlers/BandHandlers.php`](../workbench/modules/Band/Handlers/BandHandlers.php). `#[Authorize]` and `#[Transactional]` are on the [interceptors page](interceptors.md), `#[Expose]` on the [HTTP gateway page](http-gateway.md).

## Sending and asking

The `Sendable` and `Askable` traits give the message a static entry point that takes the constructor's arguments, named or positional, and returns the handler's result:

```php
$id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

$view = GetNickname::ask(id: $id);
```

From [`tests/Feature/Bus/BandModule.pest.php`](../tests/Feature/Bus/BandModule.pest.php).

The traits are optional. The `Laravents` facade sends any message, by object or by routing key:

```php
$id = Laravents::command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);

$id = Laravents::command(new GiveNickname(2, 'The Quiet One'));

$view = Laravents::query('band.get_nickname', ['id' => $id]);
```

From [`tests/Feature/Bus/BandModule.pest.php`](../tests/Feature/Bus/BandModule.pest.php).

An object finds its routing key in the registry; a key takes the payload as an array (or as an object of the handler's message class). An object message already is its payload, so passing a second payload with it is refused (`GiveNickname is already the payload; pass headers as headers: [...]`): that is almost always headers in the wrong argument.

Inside a handler or a service, inject the bus contract rather than reaching for the facade:

```php
public function __invoke(NicknameGiven $event, CommandBus $commands, #[Header('publication_id')] string $publicationId): void
{
    $commands->dispatch(new PrintHeadline($event->nicknameId, $event->memberId, $publicationId));
}
```

From [`workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php`](../workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php).

| Contract | By object | By key |
|---|---|---|
| `Laravents\Contracts\CommandBus` | `dispatch(object $command, array $headers = [])` | `send(string $routingKey, array\|object $payload = [], array $headers = [])` |
| `Laravents\Contracts\QueryBus` | `dispatch(object $query, array $headers = [])` | `ask(string $routingKey, array\|object $payload = [], array $headers = [])` |

Depend on the contracts, never on the classes in `Laravents\Bus`: `Laravents::fake()` binds itself to the contracts ([testing](testing.md)), as `Bus::fake()` binds to Laravel's dispatcher contract.

Proven by `tests/Feature/Bus/BandModule.pest.php`.

## The pending builder

Headers and an acting user go before the message, as `Mail::to()` goes before `send()`:

```php
Laravents::withHeaders(['correlation_id' => 'req-1'])->command('billing.charge', ['amount' => 5]);

Laravents::withHeader('n', '7')->withHeader('flag', '1')->withHeader('ratio', '2')->query('billing.coerce');

Laravents::withHeader('correlation_id', 'req-3')->publish(new Charged(3));
```

From [`tests/Feature/Bus/PendingMessage.pest.php`](../tests/Feature/Bus/PendingMessage.pest.php). An acting user goes the same way:

```php
Laravents::as($event->user)->command(new WelcomeFan(
    userId: is_numeric($userId) ? (int) $userId : throw new LogicException('Fans are users with integer ids.'),
    publicationId: $publicationId,
));
```

From [`workbench/modules/FanClub/Handlers/WelcomeOnRegistered.php`](../workbench/modules/FanClub/Handlers/WelcomeOnRegistered.php).

`withHeader()`, `withHeaders()` and `as()` each return a new `Laravents\Bus\PendingMessage`, chainable in any order and immutable (each call returns a new builder). It ends in `command()`, `query()` or `publish()`, which take what the facade takes. Header values are scalars or null. Six header names are reserved for Laravents and refused with an `InvalidArgumentException`; `correlation_id` is the one a caller may pass. [Metadata and the actor](metadata-and-actor.md) lists them and explains `as()`.

Proven by `tests/Feature/Bus/PendingMessage.pest.php` and `tests/Feature/Auth/ActAs.pest.php`.

## Results

A command or query returns whatever its handler returns: an id, a DTO, a collection, `null` for a `void` handler. Nothing wraps it. An exception thrown by the handler reaches the caller unchanged, after the interceptors around it have run (a `#[Transactional]` rolls back first).

A query's result type is usually a DTO in the module's `Api` (`NicknameView` above), so the caller depends on the Api and not on the module's models. Over HTTP a result is rendered as described on the [HTTP gateway page](http-gateway.md#responses).

Proven by `tests/Feature/Bus/FixtureBuses.pest.php`.

## Hydrating a keyed payload

A message sent by key with an array payload is built through the container before anything else runs: `app()->makeWith(GiveNickname::class, ['memberId' => 2, 'nickname' => 'The Quiet One'])`, parameter by parameter name. PHP's own coercion applies (`'5'` becomes `5` for an `int` parameter), a parameter with a default may be left out, and a class-typed parameter the payload does not give is resolved by the container.

A payload that cannot build the message is a `ValidationException`, so the HTTP gateway answers it with a 422 like any validation failure:

| Payload for `GiveNickname` | Message |
|---|---|
| `[]` | `The memberId field is required. (and 1 more error)` |
| `['memberId' => 'x', 'nickname' => 'The Quiet One']` | `The memberId field must be of type int.` |
| `['memberId' => null, 'nickname' => 'The Quiet One']` | `The memberId field must be of type int.` |

An object of another class as the payload is a programmer error, not a validation error: `InvalidArgumentException` (`Expected Modules\Band\Api\GiveNickname, got Modules\Band\Api\GetNickname.`).

The converter is `Laravents\Bus\PayloadConverter`, bound to the container-backed `ContainerPayloadConverter`. An application with its own serializer binds its own implementation of the interface.

Proven by `tests/Feature/Bus/PayloadConverter.pest.php` and `tests/Feature/Bus/BandModule.pest.php`.

## Did you mean

An unknown routing key throws `Laravents\Exceptions\UnknownRoutingKeyException` and names up to three registered keys of the same kind that are close, best first, as Artisan does for a mistyped command:

```
Unknown command routing key [band.give_nickame]. Did you mean one of these? [band.give_nickname], [band.replace_member]
```

Only keys of the same kind are suggested: `Laravents::query('band.give_nickame')` does not suggest the command `band.give_nickname`. With nothing close, the message is the first sentence alone. An object no handler takes throws the same exception, `No command handler for [App\Something].`

Events never throw for an unknown key or type: publishing an event nobody listens to is a no-op.

Proven by `tests/Feature/Bus/BandModule.pest.php` and `tests/Unit/UnknownRoutingKey.pest.php`.

## Validating a payload

A message that implements `Laravents\Contracts\ValidatesPayload` has a static `rules()` in Laravel's validation syntax (`GiveNickname` above). The rules run where input comes from outside PHP:

- at the [HTTP gateway](http-gateway.md#validation), before the message is built;
- in `GiveNickname::fromRequest($request)` and `GetNickname::fromRequest($request)`, for an application's own controllers.

They do not run on `GiveNickname::send()`, `Laravents::command()` or a bus dispatch. A message built in PHP is already typed, and PHP checks its constructor; running the rules on every dispatch, nested messages included, would cost a validator per message for input that is not input. A rule that must hold for every caller belongs in the handler.

`fromRequest()` validates `$request->all()` (query string and body, as `$request->validate()` does), then hydrates the message from the validated fields only, as `FormRequest::validated()` hands them back. A field with no rule is dropped, so a message with rules names every field (`sometimes` or `nullable` for an optional one):

```php
expect(GiveNickname::fromRequest(Request::create('/nicknames', 'POST', ['memberId' => '5', 'extra' => 'ignored', 'nickname' => 'The Quiet One'])))
    ->toEqual(new GiveNickname(5, 'The Quiet One'));

$error = caught(ValidationException::class, static fn (): GiveNickname => GiveNickname::fromRequest(Request::create('/nicknames', 'POST', ['memberId' => 0, 'nickname' => 'The Quiet One'])));

expect($error->errors())->toBe(['memberId' => ['The member id field must be at least 1.']]);
```

From [`tests/Feature/Http/FromRequest.pest.php`](../tests/Feature/Http/FromRequest.pest.php): `'5'` becomes `5`, the unknown `extra` is dropped, and `0` fails `min:1`. In a controller, `Laravents::command(GiveNickname::fromRequest($request))` sends it. A failure is Laravel's `ValidationException`, rendered by the application's exception handler: a redirect with errors for a form, a 422 for JSON.

Proven by `tests/Feature/Http/FromRequest.pest.php`.
