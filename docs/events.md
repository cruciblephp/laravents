# Events

## Events and their handlers

An event says that something happened in a module. It has zero to many handlers, in any module, and returns nothing. Like a command it is a plain object in the module's `Api`, usually `final readonly`; the `Publishable` trait gives it a static `publish()` that takes the constructor's arguments.

```php
final readonly class NicknameGiven
{
    use Publishable;

    public function __construct(
        public int $nicknameId,
        public int $givenBy,
        public int $memberId,
        public string $nickname,
    ) {}
}
```

From [`workbench/modules/Band/Api/NicknameGiven.php`](../workbench/modules/Band/Api/NicknameGiven.php).

Publishing takes the same three shapes as sending a command:

| Call | Reaches |
|---|---|
| `NicknameGiven::publish(nicknameId: 1, givenBy: 1, memberId: 2, nickname: 'The Quiet One')` | the handlers whose type the event is an instance of |
| `Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'))`, or `EventBus::publish($event)` injected | the same |
| `Laravents::publish('ledger.imported', ['source' => 'csv', 'rows' => 4])`, or `EventBus::publishTo($key, $payload)` | the handlers registered with `#[EventHandler('ledger.imported')]` |

An event nobody handles is a no-op, never an error.

## Matching by type and by key

An `#[EventHandler]` without a key matches by `instanceof`: it receives every published event that is an instance of its first parameter's type, the class itself, its subclasses, or, for an interface, every implementor. Several handlers of one event run in discovery order.

A keyed handler, `#[EventHandler('ledger.imported')]`, is reached only through its key, with the payload as given (an array, or an object of its parameter's class). A typed publish never reaches a keyed handler, and a keyed publish never reaches a typed one.

A pattern handler matches every key its pattern covers. In `#[EventHandler('band.*')]` the `*` stands for exactly one dotted segment, as in an AMQP topic binding: `band.*` covers `band.nickname_given` and `band.split`, not `band` or `band.tour.started`; `*.given` and `band.*.failed` work the same way. It is reached by a keyed publish of a key it covers, and by an object publish through the event's derived key, so `band.*` receives `NicknameGiven` published as an object (`band.nickname_given`) as well as `Laravents::publish('band.split', [...])`. Because one pattern covers keyed and object events alike, its first parameter names no class: it takes the payload as published (`object|array $event`, or `mixed`).

```php
#[EventHandler('band.*')]
public function __invoke(object|array $event, #[Header('message_id')] string $messageId): void
{
    Log::info('Band event', ['event' => is_object($event) ? $event::class : $event, 'message_id' => $messageId]);
}
```

From [`workbench/modules/FanClub/Handlers/ArchiveBandEvents.php`](../workbench/modules/FanClub/Handlers/ArchiveBandEvents.php): one log line for every Band event, without naming a class of Band's Api. When Band gained `MemberReplaced`, the archive received it with no change to its code.

A publish runs the handlers of its exact key (or, for an object, its typed handlers) first, then the pattern handlers that cover it, each group in discovery order, and each handler once. `#[Asynchronous]`, `#[Deferred]` and the interceptors apply to a pattern handler as to any event handler. `*` as a whole segment is the only operator: discovery refuses `#`, `*` inside a segment (`cha*.open`), a pattern with no literal segment (`*.*`), a single segment, a pattern on a command or query handler, and a pattern handler whose first parameter is a class. A native Laravel event (a class outside the modules namespace) has no key, so no pattern reaches it. `laravents:list` prints the pattern in the key column, and `laravents:show` lists the handler under every published event it covers.

Proven by `tests/Feature/Discovery/EventMatching.pest.php`, `tests/Feature/Discovery/Patterns.pest.php`, `tests/Feature/Bus/FixtureBuses.pest.php` and `tests/Feature/Bus/ReplaceMember.pest.php`.

## Sync handlers run in the publisher's transaction

A handler with no mode attribute is synchronous: it runs inline, inside the `publish()` call, so inside whatever transaction the publisher has open. If the command that published the event is `#[Transactional]`, the listener's writes commit or roll back with the command's, and an exception thrown by the listener reaches the publisher and rolls the whole transaction back.

```php
final class ThrowOnNicknameGiven
{
    #[EventHandler]
    public function onNicknameGiven(NicknameGiven $event): never
    {
        throw new RuntimeException('Listener refused the nickname.');
    }
}
```

From [`tests/Fixtures/Interception/NicknameListeners/ThrowOnNicknameGiven.php`](../tests/Fixtures/Interception/NicknameListeners/ThrowOnNicknameGiven.php): with this listener registered, `band.give_nickname` throws `Listener refused the nickname.` and no nickname row is left.

Proven by `tests/Feature/Interception/BandAccessAndTransactions.pest.php` (A7).

## After the response: `#[Deferred]`

`#[Deferred]` runs an event handler after the response, in the same process, through Laravel's `defer()`:

```php
#[EventHandler]
#[Deferred]
public function alsoLater(SignedUp $event): void
```

From [`tests/Fixtures/Deferred/Mail/Handlers/SignedUpListeners.php`](../tests/Fixtures/Deferred/Mail/Handlers/SignedUpListeners.php).

Laravel decides when: after a response below 400, at the end of an Artisan command that exits 0, after a queued job that succeeded. A 4xx or 5xx response skips it. Inside a transaction the handler is registered only when the transaction commits, through Laravel's after-commit callbacks, so a publisher that rolls back drops it even when its exception is caught and the request or command still succeeds.

The handler sees the event as it was published, with its headers (`message_id`, `correlation_id`, ...), and its interceptors run. The user is whoever is still on the guard; after an HTTP request that is the request's user.

The mode is best effort, which is its point: no outbox row, no retry. An exception is reported through the exception handler (`rescue()`) and lost, and the next deferred handler still runs. Use it for cheap work that may be lost, such as a cache warm-up or an analytics ping. Work that must happen is `#[Asynchronous]`.

`#[Deferred]` is allowed on event handlers only, and not together with `#[Asynchronous]`.

Proven by `tests/Feature/Deferred/Deferred.pest.php`.

## On a queue: `#[Asynchronous]`

`#[Asynchronous]` runs an event handler as a queued job after the publisher's transaction commits:

```php
#[EventHandler]
#[Asynchronous('press')]
public function __invoke(NicknameGiven $event, CommandBus $commands, #[Header('publication_id')] string $publicationId): void
{
    $commands->dispatch(new PrintHeadline($event->nicknameId, $event->memberId, $publicationId));
}
```

From [`workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php`](../workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php).

Publishing writes one outbox row per asynchronous handler, inside the publisher's transaction, and pushes a job carrying the row's id. If the transaction rolls back, the row and the job go with it; if it commits, the handler runs if and only if the publisher's data is there. The job runs the handler as the user who published the event, with the event's correlation id. Delivery is at least once, so the handler is idempotent on its `publication_id` header, as `PrintHeadline` is above.

The queue is the module's name in snake case unless the attribute names one ([modules](modules.md#queues)). The [outbox page](outbox.md) covers the row, retries, failures and republishing.

Proven by `tests/Feature/Async/PressHeadlines.pest.php` (A1) and `tests/Feature/Outbox/PressOutbox.pest.php`.

## Models that publish: `#[Publishes]`

`#[Publishes]` on an Eloquent model publishes an event when the model fires one of its Eloquent events:

```php
#[Publishes(NicknameGiven::class, on: 'created')]
final class Nickname extends Model
{
    protected $fillable = ['given_by', 'member_id', 'nickname'];
}
```

From [`workbench/modules/Band/Models/Nickname.php`](../workbench/modules/Band/Models/Nickname.php).

`on` is any of the model's observable events: `created`, `updated`, `deleted`, `saved`, `restored`, ..., and the model's own `$observables`. The attribute is repeatable, one per event and hook. No trait goes on the model; Laravents listens to `eloquent.created: Modules\Band\Models\Nickname` at boot, which is what `Nickname::created(...)` registers.

The event is built in one of two ways, checked at discovery (so at boot or at `laravents:cache`, never at the first save):

1. **`fromModel()`.** A `public static function fromModel(Model $model): static` on the event wins when present. The example's `NicknameGiven` has one, typed on Eloquent's `Model` rather than `Nickname`, so the Api references nothing internal:

   ```php
   public static function fromModel(Model $model): static
   {
       $nickname = $model->getAttribute('nickname');

       return new self(
           self::id($model->getKey()),
           self::id($model->getAttribute('given_by')),
           self::id($model->getAttribute('member_id')),
           is_string($nickname) ? $nickname : throw new LogicException('A nickname is a string.'),
       );
   }
   ```

   From [`workbench/modules/Band/Api/NicknameGiven.php`](../workbench/modules/Band/Api/NicknameGiven.php).

2. **The constructor.** Otherwise the constructor is filled from `$model->getAttributes()` by parameter name, through the payload converter. A parameter matches an attribute by its name or its snake_case (`authorId` takes `author_id`), and optional parameters keep their defaults:

   ```php
   #[Publishes(BookShelved::class, on: 'created')]
   #[Publishes(BookRemoved::class, on: 'deleted')]
   final class Book extends Model
   {
       public $timestamps = false;

       protected $fillable = ['title', 'author_id'];
   }
   ```

   From [`tests/Fixtures/Publishes/Library/Models/Book.php`](../tests/Fixtures/Publishes/Library/Models/Book.php); `BookShelved(int $id, string $title, int $authorId, string $edition = 'first')` is built from it. Every required parameter must name an attribute the model declares without asking the database: its key, `$fillable`, its casts, its `$attributes` defaults, its timestamp and soft-delete columns. An attribute only the database knows needs `fromModel()`.

Discovery also refuses `#[Publishes]` on anything but a concrete Eloquent model, an unknown hook (the message lists the model's events), and an event class that cannot be instantiated.

The publish happens inside the save, so inside the save's transaction: an asynchronous handler's outbox row commits or rolls back with the model. Inside a handler the event is that message's child; outside one it is a root message whose actor is the user on the guard.

It is an Eloquent model listener like any other, so what silences model listeners silences it: `saveQuietly()`, `updateQuietly()`, `deleteQuietly()` and the rest of the `*Quietly()` family, `Model::withoutEvents()`, and `Event::fake()` (`Event::fake([...])` only for the events it names).

Proven by `tests/Feature/Publishes/ModelPublications.pest.php`.

## Laravel's events in

An unkeyed `#[EventHandler]` may take an event from outside the modules: a framework event, a package's, or the application's own. Laravents subscribes to its class with `Event::listen()` at boot, so Laravel's dispatcher hands the event in and `event(new Registered($user))` is enough:

```php
#[EventHandler]
#[Asynchronous('fan_club')]
public function __invoke(Registered $event, #[Header('publication_id')] string $publicationId): void
{
    $userId = $event->user->getAuthIdentifier();
    // A framework event has no actor of its own (a starter kit fires Registered for a guest), so say who this is for.
    Laravents::as($event->user)->command(new WelcomeFan(
        userId: is_numeric($userId) ? (int) $userId : throw new LogicException('Fans are users with integer ids.'),
        publicationId: $publicationId,
    ));
}
```

From [`workbench/modules/FanClub/Handlers/WelcomeOnRegistered.php`](../workbench/modules/FanClub/Handlers/WelcomeOnRegistered.php): when Luciano signs up, the fan club welcomes him.

From there it is the normal path: metadata (a new correlation unless Context has one, the actor from the guard), interceptors, then sync, deferred or asynchronous with its outbox row. One listener is registered per class, so an event with three handlers is published once and each handler sees the same `message_id`. Under `Event::fake()` the event never reaches Laravents, as it reaches no other listener; under `Laravents::fake()` it is recorded as published.

The handler must be in a directory of `laravents.paths`. A module handler of a framework event, like the one above, already is.

Module events travel the other way only through Laravents: `event(new NicknameGiven(...))` reaches no Laravents handler, and `Laravents::publish()` is how a module event is published.

Proven by `tests/Feature/Native/NativeEvents.pest.php`.

## Known limits

- **Parent classes of external events.** Laravel's dispatcher calls a listener for the event's class and the interfaces it implements, not its parent classes. A handler of an external parent class is therefore not reached for a subclass. An event whose class and one of its interfaces both have handlers is published once, and both handlers run.
- **No outbound bridge.** A Laravents event is not dispatched to Laravel's dispatcher: `Event::listen(NicknameGiven::class, ...)` never fires on `Laravents::publish()`. A Laravel listener that must react to a module event is written as an `#[EventHandler]`.

The full list of what 1.0 leaves out is in the README's [scope](../README.md#scope).

## When not to make it asynchronous

`#[Asynchronous]` buys durability: the handler runs after commit, retried by the queue, as the publishing user, even if the process dies a moment later. It costs one thing: the publisher's transaction has already committed when the handler runs, so the handler can no longer roll it back.

That is right for a notification, a projection, a search index, a message to another module: they follow the fact. It is wrong when an external call's refusal must undo the data.

The worked case is a refund. A `refunds.issue` command records the refund and publishes `RefundIssued`, and the payment gateway must accept the refund for it to exist. As an asynchronous listener, the gateway call runs after commit: when the gateway refuses (card closed, amount above the captured total), the refund row is already committed and visible, and the listener can only record the refusal and compensate. As a synchronous listener inside the command's `#[Transactional]`, the gateway's refusal is an exception, the transaction rolls back, and no refund row ever existed.

So an external call whose refusal must roll the data back stays a sync listener inside the publisher's transaction (or is made by the command's handler itself). Keep the transaction short, since it is open for the length of the call, and make the call idempotent (pass the gateway an idempotency key from the command), since a retried transaction calls it again. `#[Asynchronous]` trades the rollback for delivery after commit; choose it when the handler follows the fact, not when it decides it.
