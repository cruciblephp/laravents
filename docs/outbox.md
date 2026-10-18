# The Outbox

## Why there is an outbox

An `#[Asynchronous]` event handler runs on a queue after the publisher's transaction commits. Two things can go wrong with a plain queued job there: the job is pushed but the transaction rolls back (the handler runs for something that never happened), or the transaction commits but the push is lost (a crash between the two, a queue that is down). The outbox closes both: the event is first written as a row in the publisher's own transaction, and the job only points at that row. The row commits or rolls back with the data; a row whose job never ran is found and delivered again.

## What a row is and when it is written

One row in `laravents_event_publications` per (event, asynchronous handler): an event with two asynchronous handlers writes two rows. Synchronous and deferred handlers write none.

| Column | Holds |
|---|---|
| `id` | A ULID; the `publication_id` header the handler receives |
| `routing_key` | The event's class, or its key for a keyed event |
| `event_class` | The event's class; null for a keyed event with an array payload |
| `event_payload` | The event, serialized as the queue serializes a job ([below](#the-payload-format)) |
| `listener` | The handler, `Class@method` |
| `headers` | Every header as published (JSON), custom ones included, so they survive the queue |
| `message_id`, `correlation_id`, `causation_id`, `actor_id`, `actor_guard` | Copied out of the headers, for reporting and indexing |
| `published_at` | When the row was written |
| `dispatched_at` | When a job was last handed over for it |
| `completed_at` | When the handler last finished; null until then |

The row is written while the event is published, inside whatever transaction is open around the publisher, and `dispatched_at` is set at once. A job is then handed to the application's bus. The job is `ShouldQueueAfterCommit`, so inside a transaction it is pushed when the transaction commits and dropped when it rolls back; outside any transaction the row is written and the job pushed immediately.

`EventPublication` (`Laravents\Outbox\EventPublication`) is an Eloquent model. An application's global model listeners (`Event::listen('eloquent.*', ...)`, an observer registered on every model) therefore run on every insert, inside the publisher's transaction; one that throws rolls the publisher back.

Proven by `tests/Feature/Outbox/Lifecycle.pest.php` (A2) and `tests/Feature/Outbox/PressOutbox.pest.php` (A1).

## The connection a row is written on

The row goes on the connection of the innermost open database transaction around the publisher, else on the default connection. Laravel's `DatabaseTransactionsManager` already records every transaction opened through a connection, on every connection, in the order they began; it is what `DB::afterCommit()` and `ShouldQueueAfterCommit` read. Laravents reads the same record, so a `#[Transactional(connection: 'other')]` handler and a hand-written `DB::connection('other')->transaction(...)` around a publish both put the row on `other`, where it commits or rolls back with that connection's data.

Two consequences:

- **The table on every such connection.** `laravents_event_publications` must exist on every connection a publisher's transaction uses: `php artisan migrate --database=other` for the package's migration, or publish it and run it there.
- **`laravents.outbox.connections`.** Republish looks for stale rows only on the connections listed there. The default is `[null]`, the default connection; an application that publishes inside transactions on `other` lists `[null, 'other']`.

The job carries the row's connection, so the worker reads the row where it was written.

Proven by `tests/Feature/Outbox/PublisherConnection.pest.php`.

## The job carries only the id

`Laravents\Async\ExecuteHandlerJob` carries the publication id and the row's connection, nothing else. The worker loads the row, rebuilds the event and its headers from it, adds the `publication_id` header, rebuilds the actor through the guard's user provider, runs the handler through its interceptors, and sets `completed_at`. First delivery and redelivery are the same job on the same row, so there is one code path and one copy of the event.

The job runs on the handler's queue, on the connection in `laravents.async.connection` (`LARAVENTS_QUEUE_CONNECTION`) or the application's default. Its Horizon tags are `laravents`, `module:<Module>` and the routing key; its display name is the listener.

## At least once, and idempotency

`completed_at` is written after the handler returns, outside the handler's own transaction. If that write fails, or the worker dies between the two, the queue delivers the job again and the handler runs a second time. Republish can deliver a row twice as well ([below](#republishing)). Delivery is at least once.

So an asynchronous handler is idempotent on its `publication_id` header, which is the same on every delivery of one row. The example passes it to the command it sends, and the command stores it under a unique key:

```php
return Headline::query()->firstOrCreate(['publication_id' => $command->publicationId], [
    'nickname_id' => $command->nicknameId,
    'member_id' => $command->memberId,
    'printed_by' => $actor->getAuthIdentifier(),
    'correlation_id' => $correlationId,
])->id;
```

From [`workbench/modules/Press/Handlers/PressHandlers.php`](../workbench/modules/Press/Handlers/PressHandlers.php), with a unique index on `headlines.publication_id`: a headline is printed once, however often its publication is delivered.

Proven by `tests/Feature/Outbox/PressOutbox.pest.php`.

## Retries and failed jobs

The queue is the only retry mechanism. A handler that throws is retried by the queue up to `laravents.async.tries` times (`LARAVENTS_TRIES`, default 3), then the job goes to `failed_jobs`, Laravel's dead-letter store, and the row stays incomplete. `php artisan queue:retry <uuid>` brings it back; the retried job completes the row like any other.

Some failures are permanent, and the job fails at once on its first attempt, without retries, as Laravel fails a job whose model is gone:

- the row does not exist on its connection;
- the listener is no longer registered (renamed, removed);
- the actor cannot be rebuilt: the user was deleted (`ModelNotFoundException`), or the guard has no user provider;
- the payload is corrupt.

Proven by `tests/Feature/Outbox/Failures.pest.php` (A3, A5), `tests/Feature/Outbox/PressOutbox.pest.php` (A11) and `tests/Feature/Outbox/Payload.pest.php`.

## Republishing

```bash
php artisan laravents:republish
php artisan laravents:republish --dry-run
```

`laravents:republish` finds rows that are incomplete and either were never dispatched or were dispatched longer ago than `laravents.outbox.republish_after` seconds (`LARAVENTS_REPUBLISH_AFTER`, default 300), on every connection in `laravents.outbox.connections`, and hands each a new job. `--dry-run` lists them (publication, connection, listener, published, dispatched) and claims nothing.

Each row is claimed before its job is dispatched: one conditional `UPDATE` that sets `dispatched_at` only if `completed_at` is still null and `dispatched_at` is still what was read. Of two republishers running at once (two servers, two schedulers), exactly one wins each row, and a row completed in the meantime is left alone.

The command is for pushes that were lost, not for handlers that failed: a job that ran and failed is in `failed_jobs`, and `queue:retry` is how it comes back. A failed job's row is incomplete, though, so once it is older than `republish_after` republish delivers it too. Set `republish_after` longer than a job's whole retry window (tries, backoff, run time), and rely on idempotency for the overlap.

Run it from any scheduler, every few minutes: with Laravel's, `Schedule::command('laravents:republish')->everyFiveMinutes()` in `routes/console.php`. It needs nothing from `schedule:run` itself: a cron line calling `php artisan laravents:republish`, a platform's scheduled task, or a Kubernetes CronJob works the same, and the claim keeps concurrent runs safe.

Proven by `tests/Feature/Outbox/Republish.pest.php` (A4).

## Worker-less hosts

Asynchronous handlers need something to run the queue.

- **`QUEUE_CONNECTION=sync`.** The job runs in the publishing process right after commit, inline: the handler runs before `send()` returns, still after the transaction committed, as the publishing user, through the same row. The caller keeps their own user afterwards. This is the setting for a host with no worker at all, and what Laravel's default `phpunit.xml` uses.
- **A scheduled drain.** On a host with a scheduler but no long-running process, a scheduled `php artisan queue:work --stop-when-empty` empties the queue every minute or so. Republish runs from the same scheduler.

Proven by `tests/Feature/Async/PressHeadlines.pest.php` ("on the sync connection the caller keeps their user").

## The payload format

`event_payload` holds the event as Laravel's queue holds a job inside its JSON payload: `serialize()` of the event, then `json_encode()` of that string. The JSON escape turns the NUL bytes that PHP writes for protected and private properties into `\u0000`, so the column is plain UTF-8 text that every driver's text column accepts, PostgreSQL's included.

`json_encode()` refuses a string that is not valid UTF-8. An event with a binary property (raw bytes, an image) throws `Laravents\Exceptions\InvalidPayloadException` when it is published, naming the event and the listener. It is thrown inside the publisher's transaction, so the publisher rolls back and nothing is half written. As for a queued job, the event encodes binary data itself (base64) before publishing.

A row whose payload is not a JSON string holding a serialized event or an array keyed by field is corrupt: the job fails at once.

Proven by `tests/Feature/Outbox/Payload.pest.php`.

## The raw-SQL edge

A transaction the transaction manager does not see is invisible to the outbox, as it is to `DB::afterCommit()`: one opened with raw SQL (`DB::unprepared('BEGIN')`, `DB::statement('START TRANSACTION')`) or on a PDO handle the application obtained itself. A publish inside such a transaction writes its row on the default connection, outside it, and the job is pushed at once. Open transactions through `DB::transaction()`, `DB::beginTransaction()` or `#[Transactional]`.

## Worked example: inner commit, outer rollback

A `#[Transactional]` command on the default connection opens a hand-written transaction on `other`, publishes an asynchronous event inside it, lets that inner transaction commit, and then throws, rolling the default connection back.

- **The row** was written on `other`, the innermost transaction at publish time. `other` committed, so the row stays, with `dispatched_at` set.
- **The job** was attached by `ShouldQueueAfterCommit` to the innermost transaction too, so it was pushed when `other` committed, while the default transaction was still open. With the database queue, its `jobs` table is on the default connection, so the push rolled back with the command, and no job remains.
- **Republish** finds the committed row once it is stale (with `other` listed in `laravents.outbox.connections`) and delivers it.

On a queue that is not on the rolled-back connection (Redis, SQS), the job would remain and the handler would run at once. Either way the handler runs, which is right: the inner transaction committed, so its event happened. The outer rollback undid the command's other writes, not the fact on `other`. Code that needs the two to commit together keeps them on one connection.

Proven by `tests/Feature/Outbox/PublisherConnection.pest.php` ("when the hand-written other transaction commits and the outer command then throws").

## The table grows

Completed rows are kept by default. They are a record of every asynchronous delivery (who published what, when it ran) and cost one row per event and handler. To keep them for a time only, set `laravents.outbox.prune_after` (`LARAVENTS_PRUNE_AFTER`) to a number of hours, the unit of Laravel's own `queue:prune-failed --hours` (720 keeps thirty days), and schedule Laravel's `model:prune` for the outbox model. `model:prune` discovers the models in `app/Models` by itself, so the outbox model is named:

```php
use Illuminate\Support\Facades\Schedule;
use Laravents\Outbox\EventPublication;

Schedule::command('model:prune', ['--model' => [EventPublication::class]])->daily();
```

It deletes the rows completed longer ago than that, with one query per chunk (`MassPrunable`, so no model events), on every connection in `laravents.outbox.connections`. A row that is not completed is never pruned, however old: it is a delivery still owed. `--pretend` counts the rows on the default connection only.

Proven by `tests/Feature/Outbox/Prune.pest.php`.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `laravents.async.connection` | `env('LARAVENTS_QUEUE_CONNECTION')` | The queue connection asynchronous handlers run on; null is the application's default |
| `laravents.async.tries` | `env('LARAVENTS_TRIES', 3)` | How many times the queue attempts a handler that throws |
| `laravents.outbox.republish_after` | `env('LARAVENTS_REPUBLISH_AFTER', 300)` | Seconds after which an incomplete, dispatched row counts as stale |
| `laravents.outbox.connections` | `[null]` | The database connections republish searches and `model:prune` prunes; null is the default |
| `laravents.outbox.prune_after` | `env('LARAVENTS_PRUNE_AFTER')` | Hours a completed row is kept before `model:prune` deletes it; null keeps every row |
