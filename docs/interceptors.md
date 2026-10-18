# Interceptors

## What an interceptor is

An interceptor runs around a handler: before it, after it, or wrapped around it, deciding whether it runs at all. It is the place for what every handler of a kind needs and none should write by hand: authorization, transactions, rate limits, auditing, tracing. Laravents ships two, `#[Authorize]` and `#[Transactional]`, and an application writes its own the same way.

Interceptors run on every path a handler is reached by: a direct send, the HTTP gateway, a deferred handler after the response, an asynchronous handler on the queue.

## `#[Authorize]`

`#[Authorize]` asks Laravel's Gate whether the message's actor may run the handler, before the handler and before anything around it:

```php
#[CommandHandler]
#[Authorize]
#[Transactional]
#[Expose]
public function give(GiveNickname $command, #[CurrentUser] Authenticatable $actor): int
```

From [`workbench/modules/Band/Handlers/BandHandlers.php`](../workbench/modules/Band/Handlers/BandHandlers.php).

The ability is the routing key unless the attribute names one (`#[Authorize('band.manage')]`), and the Gate receives the message as its argument. The module defines the ability in its service provider, as any Gate ability:

```php
// Only a member gives nicknames, and never to themselves; a fan may ask, not give.
Gate::define('band.give_nickname', static fn (User $user, GiveNickname $command): bool => $user->is_member && $user->getAuthIdentifier() !== $command->memberId);
```

From [`workbench/modules/Band/BandServiceProvider.php`](../workbench/modules/Band/BandServiceProvider.php): Luciano, a fan, is refused with a 403, and so is a member nicknaming themselves. A policy method or `Gate::before()` works as it does anywhere in Laravel.

**The actor.** The Gate is asked for the user on the guard that the message's `actor_guard` header names, and that user's identifier must equal the `actor_id` header. The headers come from that guard (or from a parent message, which got them from a guard), so the guard is what vouches: a header alone authorizes nothing. When the guard holds someone else, the interceptor throws `ActorMismatchException`, because something put the wrong actor in Context. [Metadata and the actor](metadata-and-actor.md#the-actor) has the rules.

**The outcome.**

| Gate answer | Actor | Exception | Over HTTP |
|---|---|---|---|
| allowed | any | none; the handler runs | the handler's response |
| denied | none (a guest) | `Illuminate\Auth\AuthenticationException` | 401 |
| denied | a user | `Illuminate\Auth\Access\AuthorizationException` | 403 |
| `Response::denyWithStatus($status)`, `denyAsNotFound()` | a user | `AuthorizationException` carrying the status | that status, e.g. 404 |

The exceptions are Laravel's own, so the application's exception handler renders them, at the [HTTP gateway](http-gateway.md#error-bodies) and in its own controllers alike. A refused command never opens its transaction.

`#[Authorize]` is not allowed on an event handler: an event is a fact that already happened and cannot be refused. Discovery fails with `... has #[Authorize], but #[Authorize] is not allowed on an event handler: events are facts and cannot be refused`. A handler that must check something before acting on an event sends a command that is authorized.

Proven by `tests/Feature/Interception/BandAccessAndTransactions.pest.php` (A6, and the actor headers a guard cannot back) and `tests/Feature/Http/Gateway.pest.php` (401, 403, and 404 from `denyAsNotFound()`).

## `#[Transactional]`

`#[Transactional]` runs the handler, and everything it does, in one database transaction:

| Parameter | Default | Meaning |
|---|---|---|
| `connection` | `null` | The database connection; null is the default connection |
| `attempts` | `1` | Passed to `DB::transaction($callback, $attempts)`: a deadlock or other concurrency error is retried up to this many times, as Laravel does |

It is exactly `DB::connection($connection)->transaction(fn () => ..., $attempts)`, so Laravel's semantics hold:

- **Sync listeners are inside it.** An event published by the handler reaches its synchronous handlers inline, inside the transaction; a listener that throws rolls the command back ([events](events.md#sync-handlers-run-in-the-publishers-transaction)).
- **Asynchronous handlers wait for it.** The outbox row is written inside the transaction and the job is pushed after commit, so a rollback leaves neither ([outbox](outbox.md)).
- **Nested.** A `#[Transactional]` command sent from inside another on the same connection becomes a savepoint of the outer transaction; nothing commits until the outer one does. On another connection it is a separate transaction that commits on its own.
- **On a class.** The attribute may go on the handler class, which applies it to every handler in it.

Proven by `tests/Feature/Interception/BandAccessAndTransactions.pest.php`, `tests/Feature/Async/NestedTransactions.pest.php` (A9) and `tests/Feature/Interception/Interceptors.pest.php` (deadlock retries).

## Writing an interceptor

An interceptor is a public method with one of three attributes, on any class in a directory of `laravents.paths`:

| Attribute | Runs | Takes |
|---|---|---|
| `#[Before(pointcut, precedence)]` | before the rest of the chain, which then goes on | |
| `#[Around(pointcut, precedence)]` | instead of the rest of the chain; calls `$invocation->proceed()` to run it | a `MethodInvocation` parameter (required) |
| `#[After(pointcut, precedence)]` | after the rest of the chain, with its result | a parameter named `$result` |

```php
#[After(pointcut: Traced::class, precedence: 5)]
public function after(mixed $result): void
{
    Recorder::record('after', $result);
}

#[Around(pointcut: Traced::class)]
public function around(MethodInvocation $invocation): mixed
{
    Recorder::record('around in', $invocation->handler()->method);
    $result = $invocation->proceed();
    Recorder::record('around out');

    return $result;
}

#[Before(pointcut: Traced::class, precedence: -5)]
public function before(Message $message): void
{
    Recorder::record('before', $message->routingKey);
}
```

From [`tests/Fixtures/Interception/Lab/Interceptors/LabInterceptors.php`](../tests/Fixtures/Interception/Lab/Interceptors/LabInterceptors.php). A handler carrying `#[Traced]` records `before`, `around in`, `handler`, `after`, `around out`.

An `#[Around]` that does not call `proceed()` short-circuits: the handler does not run and the interceptor's return value is the result. An `#[Around]` may also change the result on its way out.

`php artisan laravents:interceptor RateLimit --module=Band` generates one ([console](console.md#generators)).

Discovery refuses an interceptor method with more than one of the three attributes, a static or non-public one, an `#[Around]` without a `MethodInvocation` parameter, a pointcut class that does not exist, and a method that is both a handler and an interceptor.

Proven by `tests/Feature/Interception/Interceptors.pest.php` and `tests/Feature/Interception/Discovery.pest.php`.

## Pointcuts

The pointcut says which handlers an interceptor applies to. It is a class name, matched in two ways:

- **An attribute** on the handler method or on the handler class (a subclass of the attribute matches too). On the class, it applies to every handler in it, each one wrapped separately. The attribute instance found is kept and handed to the interceptor, so it can carry parameters:

  ```php
  #[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
  final readonly class Limited
  {
      public function __construct(public int $max) {}
  }
  ```

  From [`tests/Fixtures/Interception/Lab/Attributes/Limited.php`](../tests/Fixtures/Interception/Lab/Attributes/Limited.php), put on a handler as `#[Limited(max: 3)]`.

- **A class or interface of the handler class**: an interceptor whose pointcut is `Audited` applies to every handler whose class implements `Audited`.

A handler no pointcut matches runs with no interceptors. Matching happens once, at discovery: the registry stores each handler's interceptors in order, with the attribute instances, so nothing is reflected per call. `laravents:show` lists each command's and query's interceptors.

## Precedence

Interceptors applied to one handler run in precedence order, lowest first and outermost. Equal precedences keep discovery order. The default is 0.

| Interceptor | Kind | Precedence |
|---|---|---|
| `#[Authorize]` (`AuthorizationInterceptor`) | `#[Before]` | -1000 |
| `#[Transactional]` (`TransactionInterceptor`) | `#[Around]` | -500 |
| An application's interceptor | any | 0 unless set |

So by default authorization runs first, then the transaction opens, then the application's interceptors run inside it, then the handler. An interceptor that must run outside the transaction (a rate limit that should not hold a connection) takes a precedence below -500; one that must run before authorization, below -1000.

Proven by `tests/Feature/Interception/Interceptors.pest.php` and `tests/Feature/Interception/Discovery.pest.php`.

## What an interceptor may inject

An interceptor method is called through the container, like a handler. Its parameters are filled in this order:

| Parameter | Receives |
|---|---|
| named `$result` (`#[After]` only) | the result of the rest of the chain |
| `#[Header('name')]` | that message header ([metadata](metadata-and-actor.md#headers-in-handlers)) |
| typed `MethodInvocation` (`#[Around]`) | the rest of the chain: `proceed()`, `message()`, `handler()` |
| typed `Laravents\Bus\Message` | the message: kind, routing key, payload, headers |
| typed as the pointcut attribute | the attribute instance found on the handler |
| typed as the message class (or a parent or interface) | the payload object |
| anything else | the container: services, and Laravel's contextual attributes |

```php
#[Around(pointcut: Limited::class)]
public function limit(MethodInvocation $invocation, Limited $rule, #[Header('correlation_id')] string $correlationId): mixed
{
    Recorder::record('limit', [$rule->max, $correlationId]);
    $result = $invocation->proceed();

    return is_int($result) ? $result + 10 : $result;
}

#[Before(pointcut: Contextual::class)]
public function contextual(
    #[CurrentUser] ?Authenticatable $user,
    #[FromContext('correlation_id')] string $fromContext,
    #[Header('correlation_id')] string $fromHeader,
): void {
    Recorder::record('contextual', [$user?->getAuthIdentifier(), $fromContext, $fromHeader]);
}
```

From [`tests/Fixtures/Interception/Lab/Interceptors/LabInterceptors.php`](../tests/Fixtures/Interception/Lab/Interceptors/LabInterceptors.php) (`FromContext` is Laravel's `Illuminate\Container\Attributes\Context`, imported under another name). `#[Context('correlation_id')]` and the `correlation_id` header are the same value: the metadata pipe puts the message's ids in Laravel's Context for the length of the dispatch.

`#[Header]` reads the message; Laravel's attributes (`#[CurrentUser]`, `#[Config]`, `#[Context]`, `#[Auth]`, `#[DB]`, ...) read the application. The interceptor class itself is resolved from the container, so its constructor may take services too.

Proven by `tests/Feature/Interception/Interceptors.pest.php`.
