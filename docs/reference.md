# Reference

## Configuration

`config/laravents.php`, published with `php artisan vendor:publish --tag=laravents-config`.

| Key | Default | Meaning | Page |
|---|---|---|---|
| `paths` | `[base_path('modules')]` | Directories scanned for handlers, interceptors and `#[Publishes]` models | [Modules](modules.md#configuration) |
| `modules.namespace` | `'Modules'` | The modules root namespace; the segment under it is the module | [Modules](modules.md#configuration) |
| `modules.path` | `base_path('modules')` | The directory the modules namespace maps to; the generators write here | [Console](console.md#generators) |
| `modules.disabled` | `[]` | Modules left out of discovery: their handlers, interceptors and `#[Publishes]` models are not registered | [Modules](modules.md#turning-a-module-off) |
| `cache.stale` | `env('LARAVENTS_CACHE_STALE', 'scan')` | A registry cache written for another configuration: `scan` sets it aside and scans, `cache` reads it until the next `laravents:cache` | [Installation](installation.md#production-the-registry-cache) |
| `async.connection` | `env('LARAVENTS_QUEUE_CONNECTION')` | Queue connection for asynchronous handlers; null is the application's default | [Outbox](outbox.md#configuration) |
| `async.tries` | `env('LARAVENTS_TRIES', 3)` | Attempts per asynchronous handler before `failed_jobs` | [Outbox](outbox.md#retries-and-failed-jobs) |
| `outbox.republish_after` | `env('LARAVENTS_REPUBLISH_AFTER', 300)` | Seconds after which an incomplete, dispatched row is stale | [Outbox](outbox.md#republishing) |
| `outbox.connections` | `[null]` | Database connections `laravents:republish` searches and `model:prune` prunes; null is the default | [Outbox](outbox.md#the-connection-a-row-is-written-on) |
| `outbox.prune_after` | `env('LARAVENTS_PRUNE_AFTER')` | Hours a completed row is kept before `model:prune` deletes it; null keeps every row | [Outbox](outbox.md#the-table-grows) |
| `http.enabled` | `env('LARAVENTS_HTTP_ENABLED', true)` | Register the HTTP gateway's routes | [HTTP gateway](http-gateway.md#configuration) |
| `http.prefix` | `env('LARAVENTS_HTTP_PREFIX', 'laravents')` | URI prefix of the gateway | [HTTP gateway](http-gateway.md#configuration) |
| `http.middleware` | `[]` | Middleware of both gateway routes | [HTTP gateway](http-gateway.md#configuration) |
| `http.command_middleware` | `[]` | Middleware of the commands route only, after the shared list | [HTTP gateway](http-gateway.md#configuration) |
| `http.query_middleware` | `[]` | Middleware of the queries route only, after the shared list | [HTTP gateway](http-gateway.md#caching-query-responses) |
| `docs.path` | `base_path('docs/laravents')` | Where `laravents:docs` writes | [Console](console.md#laraventsdocs) |

One environment variable has no config key: `LARAVENTS_CACHE_PATH`, the registry cache file (default `bootstrap/cache/laravents.php`; absolute, or relative to the base path).

## Attributes

All in `Laravents\Attributes`.

### Handlers

| Attribute | On | Parameters | Meaning |
|---|---|---|---|
| `#[CommandHandler]` | method | `?string $routingKey = null` | The one handler of a command; without a key, derived from the first parameter's class |
| `#[QueryHandler]` | method | `?string $routingKey = null` | The one handler of a query; without a key, derived from the first parameter's class |
| `#[EventHandler]` | method | `?string $routingKey = null` | One of zero to many handlers of an event; without a key, matches events that are instances of the first parameter's type; with a key, that key; with a pattern (`band.*`, `*` one whole dotted segment), every key it covers, an object event by its derived key, after the exact or typed handlers, taking the payload as published |
| `#[Deferred]` | event handler | none | Run after the response, through `defer()`; best effort ([events](events.md#after-the-response-deferred)) |
| `#[Asynchronous]` | event handler | `?string $channel = null` | Run on a queue after commit, through the outbox; the queue defaults to the module in snake case ([events](events.md#on-a-queue-asynchronous)) |
| `#[Expose]` | command or query handler | none | Reachable through an HTTP adapter: the gateway's routes or `ExposedDispatcher` ([HTTP gateway](http-gateway.md)) |

### Interceptors

| Attribute | On | Parameters | Meaning |
|---|---|---|---|
| `#[Authorize]` | command or query handler | `?string $ability = null` | Gate check for the actor; the ability defaults to the routing key. Precedence -1000 |
| `#[Transactional]` | handler method or class | `?string $connection = null`, `int $attempts = 1` | `DB::connection($connection)->transaction(..., $attempts)` around the handler. Precedence -500 |
| `#[Before]` | interceptor method | `string $pointcut`, `int $precedence = 0` | Run before the rest of the chain |
| `#[Around]` | interceptor method | `string $pointcut`, `int $precedence = 0` | Wrap the rest of the chain; takes a `MethodInvocation` |
| `#[After]` | interceptor method | `string $pointcut`, `int $precedence = 0` | Run after the rest of the chain; a `$result` parameter receives its result |

A pointcut is an attribute on the handler method or class, or a class or interface of the handler class. Lower precedence runs first, outermost ([interceptors](interceptors.md)).

### Parameters and models

| Attribute | On | Parameters | Meaning |
|---|---|---|---|
| `#[Header]` | handler or interceptor parameter | `string $name` | Inject a message header ([metadata](metadata-and-actor.md#headers-in-handlers)) |
| `#[Publishes]` | Eloquent model, repeatable | `string $event`, `string $on` | Publish `$event`, built from the model, when the model fires the Eloquent event `$on` ([events](events.md#models-that-publish-publishes)) |

## Message traits

All in `Laravents\Concerns`. Each static method takes the message's constructor arguments, named or positional.

| Trait | Methods |
|---|---|
| `Sendable` | `send(...$arguments): mixed`, `fromRequest(Request $request): static` |
| `Askable` | `ask(...$arguments): mixed`, `fromRequest(Request $request): static` |
| `Publishable` | `publish(...$arguments): void` |

`fromRequest()` validates `$request->all()` with `rules()` when the message implements `ValidatesPayload`, then hydrates the message ([commands and queries](commands-and-queries.md#validating-a-payload)).

## Contracts

All in `Laravents\Contracts`. Depend on these, never on `Laravents\Bus\*`, so `Laravents::fake()` stands in.

| Contract | Methods |
|---|---|
| `CommandBus` | `send(string $routingKey, array\|object $payload = [], array $headers = []): mixed`; `dispatch(object $command, array $headers = []): mixed` |
| `QueryBus` | `ask(string $routingKey, array\|object $payload = [], array $headers = []): mixed`; `dispatch(object $query, array $headers = []): mixed` |
| `EventBus` | `publish(object $event, array $headers = []): void`; `publishTo(string $routingKey, array\|object $payload, array $headers = []): void` |

Headers are `array<string, scalar|null>`. A fourth interface marks a message rather than a bus: `ValidatesPayload`, with `public static function rules(): array` in Laravel's rule syntax.

`Laravents\Bus\PayloadConverter` (`convert(string $class, array|object $payload): object`, throwing `ValidationException` when the payload cannot build the class) is bound to `ContainerPayloadConverter`; rebind it to hydrate messages another way.

## The facade

`Laravents\Facades\Laravents`, aliased as `Laravents`. Its root is `Laravents\Bus\Gateway`.

| Method | Returns | Meaning |
|---|---|---|
| `command(string\|object $command, array\|object $payload = [], array $headers = [])` | the handler's result | Send a command, by object or by key |
| `query(string\|object $query, array\|object $payload = [], array $headers = [])` | the handler's result | Ask a query, by object or by key |
| `publish(string\|object $event, array\|object $payload = [], array $headers = [])` | `void` | Publish an event: an object to the handlers of its type, a key to its keyed handlers |
| `withHeader(string $name, scalar\|null $value)` | `PendingMessage` | Start a message with one header |
| `withHeaders(array $headers)` | `PendingMessage` | Start a message with these headers |
| `as(Authenticatable $user, ?string $guard = null)` | `PendingMessage` | Start a message dispatched as this user ([metadata](metadata-and-actor.md#laraventsas)) |
| `fake(array\|string\|Closure $messagesToFake = [])` | `LaraventsFake` | Replace the buses with a recording fake ([testing](testing.md)) |
| `isFake()` | `bool` | Whether `fake()` is active |
| `modules()` | `array<string, Module>` | Every module by name, sorted, the disabled ones included ([modules](modules.md#a-modules-manifest)) |
| `module(string $name)` | `?Module` | One module, or `null` when there is none |
| `manifest(string $module, class-string<T> $class)` | `?T` | The module's `module.json` as the application's manifest class, or `null`; another class than `laravents.modules.manifest` throws `LogicException` |

`Laravents\Discovery\Module` is a readonly value: `string $name`, `string $namespace`, `bool $disabled`, `?object $manifest`. `modules()`, `module()` and `manifest()` read the registry, so `fake()` does not touch them.

`PendingMessage` has `withHeader()`, `withHeaders()`, `as()`, then `command()`, `query()` or `publish()`, each taking the message and payload without headers.

After `fake()`, the facade also forwards the fake's methods: `except()`, `returns()`, `answers()`, the `assertSent`, `assertAsked` and `assertPublished` families (`...Times`, `assertNot...`, `assertNothing...`), and `sent()`, `asked()`, `published()`. Without `fake()` they throw `BadMethodCallException`.

## The HTTP adapter service

`Laravents\Http\ExposedDispatcher`, the HTTP boundary's rules for the gateway's controllers and for an adapter of your own ([writing your own HTTP adapter](http-gateway.md#writing-your-own-http-adapter)).

| Method | Returns | Meaning |
|---|---|---|
| `command(string $key, array $fields, array $headers = [])` | the handler's result | Send an `#[Expose]`d command with validated fields; `NotFoundHttpException` when the key is not one |
| `query(string $key, array $fields, array $headers = [])` | the handler's result | Ask an `#[Expose]`d query with validated fields; `NotFoundHttpException` when the key is not one |

For an adapter that describes what it serves ([describing what the adapter serves](http-gateway.md#describing-what-the-adapter-serves)):

| Method | Returns | Meaning |
|---|---|---|
| `Registry::exposed(HandlerKind $kind)` | `array<string, HandlerDefinition>` | The `#[Expose]`d command or query handlers by routing key, sorted; none for events |
| `HandlerDefinition::attribute(class-string<T> $class)` | `?T` | The handler's attribute of that class or a subclass: the method's, else its class's |
| `HandlerDefinition::attributes(class-string<T> $class)` | `list<T>` | Every one: the method's in their order, then the class's |

## Exceptions

All in `Laravents\Exceptions`. Laravents also throws Laravel's own exceptions where Laravel has one: `ValidationException` for a payload, `AuthenticationException` and `AuthorizationException` from `#[Authorize]`, `ModelNotFoundException` for a deleted actor on the queue.

| Exception | Extends | Thrown when |
|---|---|---|
| `DiscoveryException` | `LogicException` | Discovery finds an invalid handler, interceptor, `#[Publishes]` model or `module.json`: at boot during development, at `laravents:cache` in production, and reported by `laravents:verify` |
| `UnknownRoutingKeyException` | `InvalidArgumentException` | A command or query is sent with a key no handler has (with "Did you mean"), or an object no handler takes |
| `MissingHeaderException` | `LogicException` | A required `#[Header]` parameter has no such header on the message |
| `ActorMismatchException` | `LogicException` | `#[Authorize]` finds that the guard named by `actor_guard` does not hold the user named by `actor_id` |
| `InvalidPayloadException` | `InvalidArgumentException` | An event for an asynchronous handler cannot be stored: its serialized form is not valid UTF-8 |

`DiscoveryException` messages name the class, method, file and line, then the reason:

| Reason |
|---|
| `X routing key [k] is handled by both A and B.` / `X message [C] is handled by both A and B.` |
| `has more than one of #[CommandHandler], #[QueryHandler] and #[EventHandler]` |
| `must be a public, non-static method` |
| `has #[Authorize], but #[Authorize] is not allowed on an event handler: events are facts and cannot be refused` |
| `has #[Expose], but #[Expose] is only allowed on a command or query handler: events are not requested` |
| `has #[Asynchronous], but #[Asynchronous] is only allowed on an event handler` |
| `has #[Deferred], but #[Deferred] is only allowed on an event handler` |
| `has both #[Deferred] and #[Asynchronous], but a handler runs in one mode: after the response or on a queue` |
| `needs a routing key or a class or interface as its first parameter` |
| `cannot derive a routing key from [C] because it is not in a module under [Modules]; pass the key explicitly` |
| `has the routing-key pattern [p], but a pattern is only allowed on an event handler: a command or query has exactly one handler` |
| `has the routing-key pattern [p] with [#], but a pattern knows one operator: * standing for one whole dotted segment` |
| `has the routing-key pattern [p] with fewer than two segments, ...` / `with an empty segment` / `with * inside the segment [s], ...` / `with no literal segment, ...` |
| `has the routing-key pattern [p] and takes [C], but a pattern handler takes the payload as published (object\|array or mixed): a pattern covers keyed and object events alike` |
| `has more than one of #[Before], #[Around] and #[After]` |
| `has an unknown pointcut [P]` |
| `is an #[Around] without a MethodInvocation parameter` |
| `is both a handler and an interceptor` |
| `has #[Publishes], but #[Publishes] is only allowed on a concrete Eloquent model` |
| `publishes [E] on [h], which is not one of its model events (...)` |
| `publishes [E], which is not an instantiable class` |
| `publishes [E], whose fromModel() must be public static and take the model as its one parameter` |
| `publishes [E], which cannot be built from it: constructor parameter [$p] names no attribute the model declares (...) and the event has no fromModel()` |

A `module.json` names the file instead, then the reason: `is missing, and laravents.modules.manifest_required asks every module for one`, `cannot be read`, `is not valid JSON: ...`, `must hold a JSON object`, `has [f, ...], which M does not take`, `does not build M: the f field must be of type t`, or `does not build M: ...` with the converter's own words; `laravents.modules.manifest must be null or the class each module.json is hydrated into` for a name that is no class.

A header only Laravents sets, passed by a caller, is an `InvalidArgumentException` (`Header [message_id] is set by Laravents and cannot be passed.`), and a message object passed with a second payload is one too.

## Reserved headers

`message_id`, `causation_id`, `actor_id`, `actor_guard`, `timestamp`, `publication_id`. A caller may pass `correlation_id` and any header of its own ([metadata](metadata-and-actor.md#the-one-header-a-caller-may-pass)).

`Laravents\Bus\Timestamp` writes `timestamp`: `Timestamp::now()` is the value for a message sent now, `Timestamp::using(Closure(): string $using)` sets the application's own form, and `Timestamp::normally()` puts the ISO 8601 form back ([metadata](metadata-and-actor.md#the-five-keys)).

## Commands

`laravents:list`, `laravents:show`, `laravents:verify`, `laravents:docs`, `laravents:cache`, `laravents:clear`, `laravents:republish`, and the generators `laravents:module`, `laravents:command`, `laravents:query`, `laravents:event`, `laravents:interceptor`. Options and output on the [console page](console.md).
