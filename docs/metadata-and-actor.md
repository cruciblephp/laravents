# Metadata and the Actor

## The five keys

Every message gets five metadata keys when it is dispatched, and keeps them as headers:

| Key | Value |
|---|---|
| `message_id` | A new ULID, unique to this message |
| `correlation_id` | The id shared by everything one request, command or job set off: the caller's, else the parent message's, else this message's own `message_id` |
| `causation_id` | The `message_id` of the message whose handler dispatched this one; absent for a root message |
| `actor_id` | The acting user's identifier, as a string; absent for a guest |
| `actor_guard` | The name of the guard that user is on; absent for a guest |

Two more headers are set by Laravents and never by a caller: `timestamp`, the moment of dispatch in the ISO 8601 form Carbon's `toJSON()` and Eloquent write every date in: UTC, microseconds, `Z` (`2026-10-16T08:00:00.123456Z`), and `publication_id`, set only on an asynchronous handler's delivery (the outbox row's id).

`timestamp` is written with PHP's own `DateTimeImmutable`, not through Carbon. Time a test froze with `Date::setTestNow()` or `travelTo()` is honoured: while it is frozen, the moment is read from Carbon. An application that wants another form sets it once, and every message from then on carries it:

```php
use Laravents\Bus\Timestamp;

Timestamp::using(static fn (): string => (string) now()->getTimestampMs());  // milliseconds since the epoch

Timestamp::normally();  // the ISO 8601 form again
```

It is the pattern of Laravel's `Str::createUlidsUsing()`. Unused, it costs one check per message. An application whose `Date::useClass()` date overrides `toJSON()` used to get that form in `timestamp`; it sets it with `Timestamp::using()` now.

The five keys also live in Laravel's `Context` for the length of the dispatch, under the same names. Context is what Laravel adds to every log line and carries into queued jobs, so a log line written inside a handler carries its `correlation_id` with no code. When the dispatch ends, the five keys are put back as they were before it, also when the handler throws; anything else a handler adds to Context stays.

Proven by `tests/Feature/Metadata/MetadataChain.pest.php`, `tests/Feature/Metadata/ContextRestore.pest.php` and `tests/Feature/Metadata/Logging.pest.php`.

## Nested messages

A message dispatched inside a handler reads its parent from Context: it keeps the parent's `correlation_id`, and its `causation_id` is the parent's `message_id`. A command that publishes an event whose listener sends a second command produces one chain:

| Message | `message_id` | `correlation_id` | `causation_id` |
|---|---|---|---|
| `billing.charge` (command) | A | A | none |
| `Charged` (event) | B | A | A |
| `billing.send_receipt` (command) | C | A | B |

The chain survives the queue: an asynchronous handler runs with the event's own ids in Context, so what it dispatches names the event as its cause. It survives `#[Deferred]` the same way.

Proven by `tests/Feature/Metadata/MetadataChain.pest.php` (A10) and `tests/Feature/Async/PressHeadlines.pest.php` (A1).

## The one header a caller may pass

A caller may pass `correlation_id`, to join an existing trace (a request id from a gateway, a trace id from another service):

```php
Laravents::withHeaders(['correlation_id' => 'req-1'])->command('billing.charge', ['amount' => 5]);
```

From [`tests/Feature/Bus/PendingMessage.pest.php`](../tests/Feature/Bus/PendingMessage.pest.php). The whole chain then carries `req-1`. A `correlation_id` already in Context (set by request middleware, say) becomes the root the same way. Over the [HTTP gateway](http-gateway.md#the-correlation-header), a client that is not PHP sends it as the `X-Correlation-ID` request header.

The other six are reserved: passing `message_id`, `causation_id`, `actor_id`, `actor_guard`, `timestamp` or `publication_id` throws `InvalidArgumentException` (`Header [message_id] is set by Laravents and cannot be passed.`), through the facade, the builder, the bus contracts and `Laravents::fake()` alike. An actor passed as a header would be a claim nobody vouches for; the way to choose an actor is `Laravents::as()`.

Any other header is the caller's: `tenant`, `locale`, an idempotency key. It travels with the message to its handlers and interceptors, and into the outbox row of an asynchronous handler, so it survives the queue. It is not inherited by nested messages; pass it on explicitly.

Proven by `tests/Feature/Metadata/MetadataChain.pest.php` and `tests/Feature/Bus/PendingMessage.pest.php`.

## The actor

These are the rules, as settled for 1.0:

- The actor of a message is the user on the guard when it is sent; the headers carry `actor_id` and `actor_guard` as strings.
- `#[Authorize]` trusts only a user the named guard holds; a header alone authorizes nothing.
- An asynchronous handler runs as its publication's actor: the job rebuilds the user through the guard's `UserProvider` and puts the guard back afterwards.
- A plain Laravel job does not inherit the actor through Context; only the correlation and causation ids travel.
- `Laravents::as($user)` puts the user on the guard for one dispatch and adds no other trust path.

In detail:

- **Where it comes from.** A root message takes the user of the default guard (`auth()->id()`, and the default guard's name). A nested message takes its parent's actor from Context, so a command sent by a listener runs as the user who sent the first command.
- **What checks it.** `#[Authorize]` asks the Gate for the user on the `actor_guard` guard and requires that user's identifier to equal `actor_id`; otherwise `ActorMismatchException` ([interceptors](interceptors.md#authorize)). `#[CurrentUser]` in a handler reads the same guard.
- **On the queue.** The outbox row stores `actor_id` and `actor_guard`. The job rebuilds the user with the guard's provider (`retrieveById()`), puts it on that guard for the handler, and afterwards puts the guard back as it was: a worker ends every job with nobody logged in, and on the `sync` queue connection the caller keeps their own user. A user deleted between publish and delivery fails the job at once with `ModelNotFoundException`, without retries.
- **A guest.** No actor headers. An `#[Authorize]` handler refuses a guest with `AuthenticationException` unless the Gate allows guests.

Proven by `tests/Feature/Metadata/ActorAndHeaders.pest.php`, `tests/Feature/Async/PressHeadlines.pest.php` (A1, A8) and `tests/Feature/Outbox/PressOutbox.pest.php` (A11).

## `Laravents::as()`

Code that knows who it acts for when nobody is logged in says so. The example's welcome handler runs for a framework event fired for a guest, and sends an `#[Authorize]`d command for the user who registered:

```php
Laravents::as($event->user)->command(new WelcomeFan(
    userId: is_numeric($userId) ? (int) $userId : throw new LogicException('Fans are users with integer ids.'),
    publicationId: $publicationId,
));
```

From [`workbench/modules/FanClub/Handlers/WelcomeOnRegistered.php`](../workbench/modules/FanClub/Handlers/WelcomeOnRegistered.php).

`as(Authenticatable $user, ?string $guard = null)` is on the facade and on the pending builder, chainable with `withHeader()` in either order. For that one dispatch:

- the user is on the guard (the default guard when none is named), so `#[Authorize]` and `#[CurrentUser]` see them;
- the message and everything dispatched inside it carry that user as their actor, outbox rows included, so an asynchronous handler of an event published inside it runs as that user;
- the correlation and the parent's `message_id` stay, so `as()` inside a handler still produces that handler's child.

Afterwards the guard and the actor keys in Context are put back as they were, also when the handler throws. A user whose identifier is neither a string nor an integer is refused with a `LogicException`, since the job could not rebuild them.

`as()` adds no trust path: it puts the user on a real guard, and the guard is what `#[Authorize]` checks, as for a logged-in user. It is for code that already holds the user it acts for, not for turning a header into a user.

Proven by `tests/Feature/Auth/ActAs.pest.php`.

## A plain Laravel job

Laravel carries Context into every queued job. Laravents removes `actor_id` and `actor_guard` from every job's Context as it is queued (a `Context::dehydrating()` callback): the actor is a claim only a guard can back, and a plain job has no user on its guard. `message_id`, `correlation_id` and `causation_id` keep travelling.

So a job dispatched from a handler logs with the right correlation id, and a message it sends names the handler's message as its cause. But it runs as a guest: an `#[Authorize]` command it sends is refused with `AuthenticationException`. A job that acts for a user carries the user (or its id) itself and sends with `Laravents::as($user)`.

`ExecuteHandlerJob`, the job that runs asynchronous handlers, is not affected: it reads the actor from its outbox row.

Proven by `tests/Feature/Async/JobContext.pest.php`.

## Headers in handlers

`#[Header('name')]` injects a header into a handler or interceptor parameter:

```php
#[QueryHandler]
public function coerce(
    Coerce $query,
    #[Header('n')] int $n,
    #[Header('flag')] bool $flag,
    #[Header('ratio')] float $ratio,
    #[Header('label')] string $label = 'none',
    #[Header('nothing')] ?string $nothing = null,
): array {
    return [$n, $flag, $ratio, $label, $nothing];
}
```

From [`tests/Fixtures/Metadata/Billing/Handlers/BillingHandlers.php`](../tests/Fixtures/Metadata/Billing/Handlers/BillingHandlers.php).

Headers travel as strings. For an `int`, `float` or `bool` parameter a string that converts without loss is converted (`'7'` to `7`, `'2'` to `2.0`, `'1'`/`'true'` to `true`, `'0'`/`'false'` to `false`); anything else is passed as it is, so PHP's own type check rejects it under `strict_types`. A missing header gives `null` to a nullable parameter and the default to an optional one; for a required parameter it throws `MissingHeaderException`, naming the header and the handler.

`#[Header]` reads the message. Laravel's `#[Context('correlation_id')]` reads Context and holds the same value during the dispatch, so either works; `#[Header]` says that the value comes from the message.

Proven by `tests/Feature/Metadata/ActorAndHeaders.pest.php` and `tests/Feature/Bus/PendingMessage.pest.php`.
