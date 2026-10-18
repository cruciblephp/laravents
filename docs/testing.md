# Testing

## `Laravents::fake()`

`Laravents::fake()` replaces the buses with a fake that records messages instead of handling them, as `Event::fake()` and `Bus::fake()` do. With no argument everything is faked:

```php
test('1: a full fake records GiveNickname::send() and runs no handler', function (): void {
    Laravents::fake();

    expect(GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toBeNull()
        ->and(Nickname::query()->count())->toBe(0);

    Laravents::assertSent(GiveNickname::class, fn (GiveNickname $command): bool => $command->memberId === 2);
    Laravents::assertNothingPublished();
    Laravents::assertNothingAsked();
});
```

From [`tests/Feature/Testing/LaraventsFake.pest.php`](../tests/Feature/Testing/LaraventsFake.pest.php), as are the other samples on this page.

The fake is bound to the three bus contracts and the facade's root becomes a `Gateway` built on it, so every way of sending reaches it: `GiveNickname::send()`, `Laravents::command()`, `app(CommandBus::class)`, a handler's injected `CommandBus`, a native event subscribed at boot, a `#[Publishes]` model. It lives in the container, so the next test's fresh application starts with the real buses.

## What is faked

A faked message is recorded with the caller's headers and nothing else happens: no handler, no interceptor (`#[Authorize]` does not run), no metadata (`message_id` and the rest are not added), no outbox row, no job, no deferred handler. A message sent by key is hydrated into its class first, when the class is known, so it is recorded as the object; an event key whose handlers take no class is recorded as its array.

A message that is not faked goes to the real bus exactly as it came in (object or key, payload, headers), so its behaviour is the unfaked one.

What the fake does not stand in for is the registry: an unknown routing key still throws `UnknownRoutingKeyException` with its suggestion, an object no handler takes still throws, and a reserved header is still refused. What production refuses, a test does not accept.

## Faking some messages

`fake([...])` takes classes, routing keys and closures; only what matches is faked, and the rest runs for real:

```php
$fake = Laravents::fake([NicknameGiven::class]);

$id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

$fake->assertPublished(NicknameGiven::class, fn (NicknameGiven $event): bool => $event->nicknameId === $id && $event->memberId === 2);
$fake->assertPublishedTimes(NicknameGiven::class, 1);
$fake->assertNotSent(GiveNickname::class);
```

`band.give_nickname` runs for real and gives the nickname; the `NicknameGiven` its model publishes is recorded, and its asynchronous handler is not reached, so there is no outbox row and no job.

`except([...])` is the reverse: everything is faked except what it names.

```php
$fake = Laravents::fake()->except([GiveNickname::class]);

$id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
```

A message answers to its class and to its routing key, whichever way it was sent: `fake(['band.give_nickname'])` fakes `GiveNickname::send()`, and `fake([GiveNickname::class])` fakes `Laravents::command('band.give_nickname', [...])`. Matching is exact, as `EventFake`'s is: a parent class or an interface does not match. A closure in `fake()` or `except()` receives the hydrated message and its headers and returns whether it matches.

## Results

A faked command or query returns `null` unless told otherwise. `returns()` sets a command's result, `answers()` a query's, each by class or routing key, as a value or as a closure taking the message and the headers:

```php
Laravents::fake()
    ->returns(GiveNickname::class, 42)
    ->answers(GetNickname::class, fn (GetNickname $query): NicknameView => new NicknameView($query->id, 1, 2, 'The Quiet One', new CarbonImmutable('2026-10-15 12:00:00')));

expect(GiveNickname::send(memberId: 2, nickname: 'The Quiet One'))->toBe(42);
```

The class wins over the key when both are set.

## Assertions

Every assertion is on the fake (`$fake->assertSent(...)`) and on the facade (`Laravents::assertSent(...)`); on the facade without `fake()` it throws `BadMethodCallException`. The first argument is a class, a routing key, or a closure whose parameter type names the class. A callback receives the message and its headers and returns whether it matches; an int in its place asserts the number of times.

| Assertion | Fails with |
|---|---|
| `assertSent($command, $callback = null)` | `The expected [X] command was not sent.` |
| `assertSentTimes($command, $times = 1)` | `The expected [X] command was sent 1 time instead of 2 times.` |
| `assertNotSent($command, $callback = null)` | `The unexpected [X] command was sent.` |
| `assertNothingSent()` | `1 unexpected commands were sent:` then `- X sent 1 time` |
| `assertAsked($query, $callback = null)` | `The expected [X] query was not asked.` |
| `assertAskedTimes($query, $times = 1)` | `The expected [X] query was asked 0 times instead of 1 time.` |
| `assertNotAsked($query, $callback = null)` | `The unexpected [X] query was asked.` |
| `assertNothingAsked()` | `1 unexpected queries were asked:` then `- X asked 1 time` |
| `assertPublished($event, $callback = null)` | `The expected [X] event was not published.` |
| `assertPublishedTimes($event, $times = 1)` | `The expected [X] event was published 2 times instead of 1 time.` |
| `assertNotPublished($event, $callback = null)` | `The unexpected [X] event was published.` |
| `assertNothingPublished()` | `1 unexpected events were published:` then `- X published 1 time` |

`X` is the class or key as the assertion named it. The messages are Laravel's own fakes' wording, and a failure is the test runner's `AssertionFailedError`. `sent()`, `asked()` and `published()` return the matching messages as a `Collection`, as `Bus::dispatched()` does.

Headers reach the callback:

```php
Laravents::withHeader('tenant', 'acme')->publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));

Laravents::assertPublished(NicknameGiven::class, fn (NicknameGiven $event, array $headers): bool => $headers['tenant'] === 'acme');
```

Proven by `tests/Feature/Testing/LaraventsFake.pest.php` and `tests/Feature/Testing/ResetPerTest.pest.php` (a fake never leaks into the next test).

## `Queue::fake()` and `Event::fake()` next to it

The fake replaces the buses, not Laravel's. Each fake has its own job:

| Fake | Records | Use it to |
|---|---|---|
| `Laravents::fake()` | commands, queries and events sent through Laravents | test what a module sends, without its handlers |
| `Queue::fake()` | jobs, `ExecuteHandlerJob` included | test that an unfaked event's asynchronous handler was queued, without running it |
| `Event::fake()` | Laravel's events, Eloquent's included | test a framework event; it also keeps `#[Publishes]` models and native-event handlers from reaching Laravents |
| `Bus::fake()` | the application's jobs | Laravents' engine has its own dispatcher, so this does not fake Laravents messages; it does see `ExecuteHandlerJob` |

With `Queue::fake()` an unfaked event still writes its outbox row (the publish ran for real) and the job is recorded rather than pushed: `Queue::assertPushed(ExecuteHandlerJob::class)`.

Proven by `tests/Feature/Bus/Engine.pest.php` and `tests/Feature/Native/NativeEvents.pest.php`.

## Running a worker in a test

An end-to-end test of an asynchronous handler runs the real queue in the test process: the database queue on the test database, then `queue:work --stop-when-empty`. The suite's helper:

```php
function runWorker(string $queue): string
{
    auth()->forgetGuards();

    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => $queue,
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--memory' => 1024,
    ]);

    return Artisan::output();
}
```

From [`tests/Pest.php`](../tests/Pest.php). Three details matter:

- `auth()->forgetGuards()`: a real worker is its own process with nobody logged in. Without this the worker inherits the test's `actingAs()` user, and a test of "the handler runs as the publishing user" passes even if the job never puts its actor on the guard.
- `--sleep=0`: otherwise the worker waits three seconds before it notices the queue is empty.
- `--memory`: a test process can be past the default 128 MB, which stops the worker after one job.

```php
$nicknameId = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

$jobs = queuedJobs();
expect($jobs)->toHaveCount(1)
    ->and(Headline::query()->count())->toBe(0);

runWorker('press');

$headline = Headline::query()->sole();
```

From [`tests/Feature/Async/PressHeadlines.pest.php`](../tests/Feature/Async/PressHeadlines.pest.php) (A1).

For a test that only needs the handler to have run, `QUEUE_CONNECTION=sync` runs it inline after commit, with no worker ([outbox](outbox.md#worker-less-hosts)).

## After-commit callbacks under `RefreshDatabase`

`RefreshDatabase` wraps each test in a transaction and installs Laravel's testing `DatabaseTransactionsManager`. That manager runs after-commit callbacks (`ShouldQueueAfterCommit` pushes, `DB::afterCommit()`, `#[Deferred]` registration) when a commit brings the connection back to level 1, the test's own wrapping transaction: that is, when the application's outermost transaction commits. A callback registered while only the wrapping transaction is open runs at once, as it would outside any transaction. So inside a wrapped test an asynchronous handler's job is pushed when the publisher's `#[Transactional]` commits, as it would be in production, and not held back by the test's transaction.

That is why the asynchronous tests can publish inside a wrapped test and still find the job in `jobs` right after `send()` returns, and why a nested transactional command's job appears only once the outer command commits. `tests/Feature/Async/NestedTransactions.pest.php` (A9) proves both: the event published by the inner command sees no job while the outer transaction is open, one job after it commits, and none when it rolls back.

The testing manager runs callbacks at level 1 on every connection, as if each were wrapped by the test. A test that commits a nested transaction on a second connection the test does not wrap gets the push early; such a test installs a plain `Illuminate\Database\DatabaseTransactionsManager` instead, as `tests/Feature/Outbox/PublisherConnection.pest.php` does.

## A real database

A suite that runs on a real database (MySQL, PostgreSQL) instead of SQLite in memory runs `migrate:fresh` at its first test. Guard the database name before it does, so a misconfigured environment never wipes a database that is not a test one: check in the test case's `setUp()` that `DB::getDatabaseName()` ends in `_test` and stop the run otherwise.

## Module tests on Testbench

A module that lives in an application is tested by the application's own suite and `TestCase`; `laravents:module` writes a first test there (`tests/Feature/Modules/BandTest.php`, PHPUnit or Pest as `make:test` decides).

A module extracted into its own package is tested the way this package is: Orchestra Testbench, with the example application in `workbench/` and `WithWorkbench`, package discovery for `LaraventsServiceProvider`, and the test environment in the test case:

```php
class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /** Package discovery on: LaraventsServiceProvider and the Laravents alias come from composer.json's extra.laravel. */
    protected $enablesPackageDiscoveries = true;
```

From [`tests/TestCase.php`](../tests/TestCase.php). The module's providers go in `testbench.yaml`, and `laravents.paths` points at the module's directory from a workbench service provider ([`workbench/app/Providers/WorkbenchServiceProvider.php`](../workbench/app/Providers/WorkbenchServiceProvider.php)), so `vendor/bin/testbench serve` and the console commands see the same application as the tests.
