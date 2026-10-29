# Changelog

Every release, as it ships.

## v1.1.0 - 2026-10-29

- Added `Laravents::modules()` and `Laravents::module($name)`: every module as a `Laravents\Discovery\Module` (`name`, `namespace`, `disabled`, `manifest`), from the registry, so `laravents:cache` caches it and `Laravents::fake()` leaves it
- Added module manifests: a module's `module.json`, hydrated into the class `laravents.modules.manifest` names, its constructor the schema, each field held to its type as `strict_types` would; a file that does not fit fails discovery and `laravents:verify` naming the file, and `laravents.modules.manifest_required` makes a missing one fail too
- Added `Laravents::manifest($module, $class)`: a module's manifest typed as the application's class
- Added `LaraventsServiceProvider::scan()`'s optional container, which hydrates the manifests
- Added `Registry::exposed($kind)`: the `#[Expose]`d command or query handlers by routing key, sorted, for an adapter that describes what it serves
- Added `HandlerDefinition::attribute($class)` and `attributes($class)`: a handler's own attribute, the method's then the class's, as an interceptor's pointcut is matched, which now reads it the same way
- Improved a mistyped payload field: the converter names it from the constructor's declared types, as the container's call coerces, instead of reading PHP's TypeError message

## v1.0.1 - 2026-10-18

- Removed the `spatie/php-structure-discoverer` dependency, and `spatie/laravel-package-tools` with it: the classes in `laravents.paths` are found with Laravel's Symfony Finder and PHP's tokenizer, in the same order, so `laravel/framework` is the only requirement

## v1.0.0 - 2026-10-18

- Added commands, queries and events as plain objects with the `Sendable`, `Askable` and `Publishable` traits: `GiveNickname::send(...)`, `GetNickname::ask(...)`, `NicknameGiven::publish(...)`
- Added the `Laravents` facade (`command()`, `query()`, `publish()`) and the `CommandBus`, `QueryBus` and `EventBus` contracts, on Laravel's Bus dispatcher and Pipeline; the pending builder (`Laravents::withHeader()`, `withHeaders()`, `as()`) for one dispatch with headers
- Added `Laravents::as($user, ?$guard)` and `PendingMessage::as()`: dispatch one message as a given user
- Added routing keys from the module and the class name (`band.give_nickname`), or explicit keys with an array payload
- Added "Did you mean" for an unknown routing key: up to three registered keys of the same kind
- Added `#[CommandHandler]`, `#[QueryHandler]` and `#[EventHandler]`, found by attribute in the configured paths; handler parameters resolved by the container, with `#[Header]` for message headers
- Added `#[Asynchronous(queue)]`: an event handler runs on Laravel's queue, through the outbox
- Added `#[Deferred]`: an event handler runs after the response through `defer()`, registered after commit
- Added routing-key patterns: `#[EventHandler('chat.*')]` matches every key whose `*` segment it covers, keyed publishes and object events by their derived key, after the exact or typed handlers; `*` as one whole segment is the only operator, and discovery refuses anything else
- Added `#[Expose]`: a command or query handler is served by the HTTP gateway
- Added `#[Publishes(Event::class, on: 'created')]` on an Eloquent model: the event is published on that model event, inside the save's transaction
- Added native Laravel events in: an `#[EventHandler]` of a framework, package or application event is subscribed with `Event::listen()`
- Added the registry cache: `laravents:cache` and `laravents:clear`, part of `php artisan optimize` and `optimize:clear`
- Added `laravents.modules.disabled`: a module listed there is left out of discovery, so its messages reach nobody; `laravents:verify` still checks it
- Added `#[Authorize]`: the Gate, with the routing key as the ability, for the user the named guard holds
- Added `#[Transactional]`: the handler in a database transaction, on a named connection if given
- Added custom `#[Around]`, `#[Before]` and `#[After]` interceptors, applied by pointcut (an attribute, class or interface)
- Added five keys in Laravel's Context for every message: `message_id`, `correlation_id`, `causation_id`, `actor_id`, `actor_guard`
- Added the actor rules: the actor is the user on the guard when a message is sent; `#[Authorize]` trusts only a user the guard holds, never a header; an asynchronous handler runs as its publication's actor, rebuilt through the guard's `UserProvider`
- Added the outbox: one row per event and asynchronous listener, written in the publisher's transaction, so a listener runs if and only if the publisher committed
- Added the outbox row on the connection of the innermost open transaction, `#[Transactional]` or hand-written, as Laravel's transaction manager records it
- Added the outbox payload, stored as Laravel's queue stores a job, serialized inside JSON, so it fits a text column on every driver; a payload that is not valid UTF-8 throws `InvalidPayloadException` at publish
- Added `laravents:republish [--dry-run]`: re-dispatch rows that were never picked up, on every connection in `laravents.outbox.connections`
- Added pruning: with `laravents.outbox.prune_after` set (hours, as Laravel's prune commands count), `model:prune --model="Laravents\Outbox\EventPublication"` deletes the rows completed longer ago, on every outbox connection; incomplete rows are never pruned, and with no value every row is kept
- Added permanent failures (a missing row, listener or actor, a corrupt payload) that fail the job at once; only what the handler throws is retried
- Added `Laravents::fake()`, `fake([...])` and `->except([...])`, `returns()` for commands and `answers()` for queries; the `assertSent`, `assertAsked` and `assertPublished` families with their `Not`, `Nothing` and `Times` variants, on the fake and on the facade
- Added `laravents:list [--module=]`, `laravents:show <Module> [--json]`, `laravents:verify [--json] [--allow-cycles]`, `laravents:docs [--path=] [--realpath] [--check]`, `laravents:cache`, `laravents:clear`, `laravents:republish [--dry-run]`
- Added generators on Laravel's `GeneratorCommand`: `laravents:module` (its first test is PHPUnit or Pest as `make:test` decides, `--pest`/`--phpunit`), `laravents:command`, `laravents:query`, `laravents:event --async[=queue]`, `laravents:interceptor --around|--before|--after --pointcut=`; stubs publishable under `laravents-stubs`
- Added a Laravents section in `php artisan about`
- Added `POST /laravents/commands/{key}` and `GET|POST /laravents/queries/{key}` for `#[Expose]`d handlers (`laravents.http.enabled`, `.prefix`, `.middleware`): `rules()` validation and hydration (422), `#[Authorize]` (401/403), one identical 404 for anything not exposed; `fromRequest()` on `Sendable` and `Askable`
- Added `laravents.http.command_middleware` and `.query_middleware`: middleware for one route only, after the shared `.middleware` (`cache.headers` on queries, an idempotency-key middleware on commands)
- Added the `X-Correlation-ID` request header: a client joins an existing trace on either route; validated `required|string|max:255` (422), so it fits the outbox column
- Added `laravents:verify`: dependency direction (other modules, and the application code in `app/`, use a module only through its `Api`), Api purity, cycles and the registry; exit 1 on a violation, `--json` for CI
- Added `laravents:docs`: one Markdown page per module and an index with a Mermaid dependency graph; `--check` exits 1 when they are stale
- Added `CONTRIBUTING.md` (how a change is judged) and `SECURITY.md` (supported versions, private reporting, scope)
- Added the manual under `docs/`: installation, modules, commands and queries, events, interceptors, metadata and the actor, the outbox, the HTTP gateway, testing, the console, verification and docs, and a reference
