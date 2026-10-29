<?php

declare(strict_types=1);

return [

    /*
    | Directories scanned for #[CommandHandler], #[QueryHandler] and #[EventHandler].
    | In production, php artisan optimize (or laravents:cache) writes the registry
    | to bootstrap/cache/laravents.php (LARAVENTS_CACHE_PATH) and boot reads that
    | instead of scanning; a handler added afterwards is invisible until the next
    | laravents:cache, as a route is after route:cache.
    */
    'paths' => [
        base_path('modules'),
    ],

    /*
    | Modules are the namespace segments under this root. Routing keys and default
    | queues are derived from them: Modules\Band\Api\GiveNickname becomes band.give_nickname.
    | The generators (laravents:module, laravents:command, ...) write into path,
    | the directory the namespace maps to in composer.json.
    |
    | A module named in disabled is left out of discovery: its handlers,
    | interceptors and #[Publishes] models are not registered, so its messages
    | reach nobody, as if the module were not there. Its service provider still
    | loads. The registry cache holds the list it was built with, so a change
    | takes effect after the next laravents:cache. laravents:verify still checks
    | every module.
    |
    | A module may describe itself in a module.json in its directory under path.
    | manifest names the class each file is hydrated into, as a message is from
    | its payload: the class is the application's schema, its constructor the
    | fields, and a file that does not build it fails discovery and
    | laravents:verify. Laravents::manifest('Band', Manifest::class) reads one,
    | typed. null reads no file. With manifest_required, a module with no
    | module.json fails too.
    */
    'modules' => [
        'namespace' => 'Modules',
        'path' => base_path('modules'),
        'disabled' => [],
        'manifest' => null,
        'manifest_required' => false,
    ],

    /*
    | Asynchronous handlers run as queued jobs. The queue is the only retry
    | mechanism for a dispatched job; failed_jobs is the dead-letter store.
    */
    'async' => [
        'connection' => env('LARAVENTS_QUEUE_CONNECTION'),
        'tries' => (int) env('LARAVENTS_TRIES', 3),
    ],

    /*
    | laravents:republish re-dispatches outbox rows that were never picked up:
    | completed_at is null and dispatched_at is null or older than this many seconds.
    |
    | An outbox row is written on the connection of the innermost open transaction
    | around the publisher, else on the default connection, so it commits or rolls
    | back with the publisher's data. The laravents_event_publications table must
    | therefore exist on every connection a publisher's transaction uses, whether
    | #[Transactional(connection: ...)] or a hand-written
    | DB::connection(...)->transaction() (php artisan migrate --database=...), and
    | each such connection must be listed here for republish to find its rows.
    | null is the default.
    |
    | Completed rows are kept unless prune_after is set, in hours as Laravel's
    | own prune commands count: then model:prune
    | (--model="Laravents\Outbox\EventPublication", since it only discovers
    | app/Models by itself) deletes the ones completed longer ago, on every
    | connection listed here. Incomplete rows are never pruned.
    */
    'outbox' => [
        'republish_after' => (int) env('LARAVENTS_REPUBLISH_AFTER', 300),
        'connections' => [null],
        'prune_after' => env('LARAVENTS_PRUNE_AFTER'),
    ],

    /*
    | The HTTP gateway: POST {prefix}/commands/{key} and GET|POST {prefix}/queries/{key}
    | (route names laravents.commands and laravents.queries) for #[Expose]d handlers
    | only; any other key is a 404. Commands take the request body, queries the query
    | string on GET and the body on POST. #[Authorize] applies as on any other path,
    | to the request's authenticated user.
    |
    | The middleware is the app's choice, as for any route: usually ['api'] (add
    | 'auth:sanctum' for tokens), or ['web'] for session auth, CSRF included. Rate
    | limiting is Laravel's 'throttle:60,1' or a named limiter; Laravents has none.
    |
    | command_middleware and query_middleware go on one route only, after the
    | shared middleware: 'cache.headers:etag' on queries, an idempotency key
    | middleware on commands.
    */
    'http' => [
        'enabled' => (bool) env('LARAVENTS_HTTP_ENABLED', true),
        'prefix' => env('LARAVENTS_HTTP_PREFIX', 'laravents'),
        'middleware' => [],
        'command_middleware' => [],
        'query_middleware' => [],
    ],

    /*
    | laravents:docs writes README.md (the module list and a Mermaid dependency
    | graph) and one <Module>.md per module here, for the repository's README to
    | link to and GitHub to render. --path overrides it; a relative path is under
    | the base path. In CI, run laravents:docs --check next to laravents:verify: it
    | writes nothing and exits 1, naming each file, when a page is stale or missing.
    */
    'docs' => [
        'path' => base_path('docs/laravents'),
    ],

];
