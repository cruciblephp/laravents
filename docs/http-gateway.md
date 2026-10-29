# The HTTP Gateway

## `#[Expose]`

`#[Expose]` on a command or query handler makes it reachable over HTTP, through the gateway's routes or an [adapter of your own](#writing-your-own-http-adapter). Nothing else is: a handler without it has no URL. The buses do not check it: a dispatch from PHP reaches any handler.

```php
#[CommandHandler]
#[Authorize]
#[Transactional]
#[Expose]
public function give(GiveNickname $command, #[CurrentUser] Authenticatable $actor): int

#[QueryHandler]
#[Expose]
public function get(GetNickname $query): NicknameView
```

From [`workbench/modules/Band/Handlers/BandHandlers.php`](../workbench/modules/Band/Handlers/BandHandlers.php). `band.replace_member`, in the same class, has no `#[Expose]`, so it has no URL.

`#[Expose]` on an event handler fails discovery: events are published by the module that owns them, not requested. `laravents:list` and `laravents:show` print each exposed handler's route in their HTTP column.

Proven by `tests/Feature/Http/Expose.pest.php`.

## The routes

| Method | URI | Route name | Payload |
|---|---|---|---|
| `POST` | `/laravents/commands/{key}` | `laravents.commands` | the request body |
| `GET` | `/laravents/queries/{key}` | `laravents.queries` | the query string |
| `POST` | `/laravents/queries/{key}` | `laravents.queries` | the request body |

The body is JSON, or a form body under the `web` group. A command never reads the query string: `POST /laravents/commands/band.give_nickname?memberId=2` has no payload. A query takes POST for inputs too long or too structured for a query string.

Every key the route will not serve is one 404 with the same body: a key that does not exist, a handler that is not exposed, a command key on the queries route and a query key on the commands route. The controllers decide that from the registry before the bus is touched, so the body never says whether a key exists and never carries a "Did you mean" suggestion.

The routes are three segments deep and a routing key always holds a dot (`band.give_nickname`), so an application's single-segment catch-all route (`/{page}`) never matches a gateway URL, and Laravel's default parameter pattern takes the whole key, dot included, as `{key}`.

Proven by `tests/Feature/Http/Routes.pest.php` and `tests/Feature/Http/Gateway.pest.php`.

## The correlation header

A client joins an existing trace by sending `X-Correlation-ID` on either route. Its value becomes the message's `correlation_id`, so the command or query, the events it publishes and their outbox rows carry the client's id instead of a new one, as `Laravents::withHeaders(['correlation_id' => ...])` does from PHP. The header is named with hyphens because nginx drops request headers with underscores by default.

The value is validated as Laravel validates a field, `required|string|max:255`, to fit the outbox's `correlation_id` column: anything else is a 422 with an error under `correlation_id`, before the handler runs. Without the header, the message starts its own trace. It is the only request header the gateway reads.

Proven by `tests/Feature/Http/CorrelationHeader.pest.php`.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `laravents.http.enabled` | `env('LARAVENTS_HTTP_ENABLED', true)` | Register the routes at all. Off, there are no routes and the HTTP column is empty |
| `laravents.http.prefix` | `env('LARAVENTS_HTTP_PREFIX', 'laravents')` | The URI prefix |
| `laravents.http.middleware` | `[]` | The middleware for both routes, commands and queries alike |
| `laravents.http.command_middleware` | `[]` | Middleware for the commands route only, after the shared list |
| `laravents.http.query_middleware` | `[]` | Middleware for the queries route only, after the shared list |

The routes are registered in the package provider's `boot()`, inside `Route::group(['prefix' => ..., 'middleware' => ...])`, as Horizon and Telescope register theirs; `route:cache` caches them with the application's.

Middleware, authentication, rate limiting and caching are the application's, as for any route. Laravents adds none of its own. A typical API sets `['api', 'auth:sanctum', 'throttle:60,1']`; an application with session authentication sets `['web', 'auth']`. A client sends `Accept: application/json`, as for any Laravel API: without it, Laravel's handler redirects a guest to the `login` route instead of answering 401.

Proven by `tests/Feature/Http/Routes.pest.php`.

## Validation

When the message implements `ValidatesPayload`, its `rules()` run on the payload first, through Laravel's validator, and the message is built from the validated fields only. A field with no rule is dropped. Then the payload converter builds the message; a payload that cannot build it (a missing or mistyped field) fails the same way.

Input reaches the handler as the application's middleware leaves it: trimming strings and turning empty strings into `null` are the application's global middleware, not Laravents'. A validated string fills a typed constructor parameter by PHP's own coercion, as a FormRequest's fields do when passed to a typed method.

Both failures are Laravel's `ValidationException`, so the 422 body is Laravel's default `{message, errors}`, rendered by the application's exception handler:

```json
{"message":"The member id field must be at least 1.","errors":{"memberId":["The member id field must be at least 1."]}}
```

`message` is the first error, followed by `(and N more errors)` when there are more. The handler never runs on a 422.

Proven by `tests/Feature/Http/Gateway.pest.php`.

## Responses

| The handler returns | The response |
|---|---|
| a `Responsable` (a `JsonResource`, a `Response`, anything implementing it) | that response, as it is: its status, headers and body |
| `null`, or nothing (`void`) | `204 No Content`, empty body |
| anything else | `200`, `{"data": ...}` through Laravel's JSON serialization |

A query returning a DTO is answered as its public properties; a Carbon date serializes as ISO 8601 in UTC, as Eloquent's dates do:

```json
{"data":{"id":1,"givenBy":1,"memberId":7,"nickname":"The Quiet One","givenAt":"2026-10-15T10:00:00.000000Z"}}
```

A `JsonResource` is not wrapped again, so its own `data` wrapper, `additional()` and `meta` stay as the resource builds them.

Proven by `tests/Feature/Http/Responses.pest.php` and `tests/Feature/Http/Gateway.pest.php`.

## Authorization

`#[Authorize]` runs as on any other path, for the request's authenticated user on the default guard ([interceptors](interceptors.md#authorize)). The Gate's answer becomes Laravel's exception and the application's handler renders it: a guest is a 401, a refused user a 403. A Gate response that carries a status keeps it: an ability answering `Response::denyAsNotFound()` gives a 404 through Laravel's handler, for resources whose existence must not show to someone who may not see them.

Proven by `tests/Feature/Http/Gateway.pest.php` ("a Gate answering denyAsNotFound() is a 404").

## Error bodies

The error bodies are the application's exception handler's, unchanged: Laravents throws Laravel's own exceptions and renders nothing itself. With Laravel 13's default handler, `app.debug` off and `Accept: application/json`:

| Status | When | Body |
|---|---|---|
| 401 | A guest reaches an `#[Authorize]` handler (or the `auth` middleware) | `{"message":"Unauthenticated."}` |
| 403 | The Gate refuses the user | `{"message":"This action is unauthorized."}`, or the Gate response's own message |
| 403 | `useOriginOnly()` and a cross-origin `Sec-Fetch-Site` ([CSRF](#csrf)) | `{"message":"Origin mismatch."}` |
| 404 | Unknown, unexposed or wrong-kind key | `{"message":""}` |
| 404 | The Gate answers `denyAsNotFound()` | `{"message":"Not Found"}`, or the response's own message |
| 404 | The handler throws `ModelNotFoundException` | `{"message":"No query results for model [...] 1"}` |
| 419 | CSRF token missing or wrong under `web` | `{"message":"CSRF token mismatch."}` |
| 422 | `rules()` or hydration fails | `{"message":"...","errors":{"field":["..."]}}` |
| 429 | The application's `throttle` middleware | `{"message":"Too Many Attempts."}`, with `Retry-After` and `X-RateLimit-*` headers |

Anything else a handler throws is a 500, `{"message":"Server Error"}`, with the exception, file, line and trace added when `app.debug` is on.

An application that wants RFC 9457 problem details (`application/problem+json` with `type`, `title`, `status`, `detail`) configures that in its exception handler, with `$exceptions->render()` or `$exceptions->respond()` in `bootstrap/app.php`; it then applies to the gateway as to every other route. Laravents adds no error body of its own.

Proven by `tests/Feature/Http/Gateway.pest.php`.

## CSRF

Whether a gateway request needs a CSRF token depends only on `laravents.http.middleware`:

| `laravents.http.middleware` | Authentication | CSRF |
|---|---|---|
| `['web', ...]` | the session cookie | yes: Laravel 13's `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` is in the `web` group |
| `['api', 'auth:sanctum', ...]` with tokens | a bearer token | no: the `api` group has no CSRF middleware |
| `['api', 'auth:sanctum', ...]` with Sanctum's SPA authentication (`statefulApi()`) | the session cookie | yes, for requests from Sanctum's stateful domains: Sanctum runs the CSRF check on them (see Sanctum's documentation) |
| `[]` (the default) | none unless added | no |

Under `web`, `PreventRequestForgery` lets `GET`, `HEAD` and `OPTIONS` through, so a query asked by GET needs no token, while `POST /laravents/commands/*` and `POST /laravents/queries/*` do. The token is read from the `_token` field, then the `X-CSRF-TOKEN` header, then the encrypted `X-XSRF-TOKEN` header. A page rendered by Blade sends the meta tag's token:

```http
POST /laravents/commands/band.give_nickname HTTP/1.1
Accept: application/json
Content-Type: application/json
Cookie: laravel_session=...
X-CSRF-TOKEN: <the content of <meta name="csrf-token" content="{{ csrf_token() }}">>

{"memberId": 2, "nickname": "The Quiet One"}
```

A single-page application sends the `XSRF-TOKEN` cookie's value back as a header, as axios does by itself:

```http
POST /laravents/commands/band.give_nickname HTTP/1.1
Accept: application/json
Content-Type: application/json
Cookie: laravel_session=...; XSRF-TOKEN=...
X-XSRF-TOKEN: <the XSRF-TOKEN cookie's value>

{"memberId": 2, "nickname": "The Quiet One"}
```

A missing or wrong token is a 419 (`{"message":"CSRF token mismatch."}`). A request whose `Sec-Fetch-Site` header is `same-origin` passes without a token (`same-site` too, after `allowSameSite()`). An application that calls `PreventRequestForgery::useOriginOnly()` checks only that header: any other request, one without the header included, is then a 403 (`{"message":"Origin mismatch."}`), not a 419, and no `XSRF-TOKEN` cookie is set. Laravel skips the check while running unit tests, so a feature test needs no token.

Under the `api` group with bearer tokens there is no CSRF at all: no token, no 419, and a cookie is never what authenticates the request.

## Caching query responses

A GET query's response gets whatever cache headers the middleware sets; with none, Symfony's default `Cache-Control: no-cache, private`. Laravel's `cache.headers` middleware adds `Cache-Control`, an `ETag` and `304 Not Modified` answers, for GET and HEAD requests with a successful response only:

`'query_middleware' => ['cache.headers:private;max_age=60;etag']`

On `query_middleware` it reaches the queries route alone, after the shared `middleware`. `command_middleware` is the same for the commands route: a stricter `throttle:`, or an idempotency-key middleware, which belongs to the application or to the package that owns its wire format, not to Laravents.

Proven by `tests/Feature/Http/Routes.pest.php` ("a query route with cache.headers answers 304").

## Writing your own HTTP adapter

The gateway's two routes are one wire format. An application or package that speaks another (an envelope of several commands per request, a front-end command bus such as vapor's) calls `Laravents\Http\ExposedDispatcher`, the service both controllers call, so the HTTP boundary's rules hold for it too:

| Method | Returns |
|---|---|
| `command(string $key, array $fields, array $headers = [])` | the command handler's result, unshaped |
| `query(string $key, array $fields, array $headers = [])` | the query handler's result, unshaped |

Each resolves the key, refuses a handler that is not `#[Expose]`d or not of that kind, validates the fields with the message's `rules()`, then dispatches through the bus with the headers. What it throws, and what Laravel's exception handler renders for it when the adapter lets it through:

| Exception | When | Rendered |
|---|---|---|
| `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` | Unknown key, not `#[Expose]`d, or a key of the other kind | 404, as the gateway's routes answer |
| `Illuminate\Validation\ValidationException` | `rules()` fail, or the fields cannot build the message | 422 `{message, errors}` |
| `Illuminate\Auth\AuthenticationException` / `Illuminate\Auth\Access\AuthorizationException` | `#[Authorize]` | 401 / 403 (404 for a Gate's `denyAsNotFound()`) |
| `InvalidArgumentException` | A reserved header in `$headers` | 500: a bug in the adapter, as for the facade |

`$headers` is what the adapter passes: `correlation_id` and headers of its own, never a [reserved one](reference.md#reserved-headers). The gateway's controllers pass `correlation_id` from [the correlation header](#the-correlation-header) and nothing else. Everything else is the adapter's wire: the envelope, batching, idempotency keys, and the error bodies it writes when it catches these exceptions instead of letting Laravel render them. `HandlerResponse::from()` is the gateway's shaping of a result; an adapter shapes its own.

A vapor-style controller takes the request's envelope, loops over its items, and for each calls `command()` or `query()` with the item's key, its fields and `['correlation_id' => $envelopeId]`; it catches `NotFoundHttpException` and `ValidationException` per item to write that item's error into its own response, so one bad item does not fail the batch, and returns the collected results in its own shape. It never reads the registry: whether a key is reachable is the service's decision, as it is for the gateway's routes.

Proven by `tests/Feature/Http/ExposedDispatcher.pest.php`.

### Describing what the adapter serves

An adapter that describes its handlers, a client generated from them or a list of tools, reads what the gateway serves from the registry, and each handler's attributes of the application's own from its definition, with no reflection of its own:

```php
foreach (app(Registry::class)->exposed(HandlerKind::Command) as $key => $handler) {
    $display = $handler->attribute(Display::class);   // the method's #[Display], else its class's, or null
}
```

- **`Registry::exposed($kind)`** is the `#[Expose]`d command or query handlers by routing key, sorted; none for events, which are never exposed.
- **`HandlerDefinition::attribute($class)`** is the handler's attribute of that class, or of one that extends or implements it: the method's, else its class's, as an interceptor's pointcut is matched. **`attributes($class)`** is every one, the method's in their order, then the class's.

Describing is not deciding: what a request may reach is still `ExposedDispatcher`'s, above.

Proven by `tests/Feature/Discovery/HandlerAttributes.pest.php`.
