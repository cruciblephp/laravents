# Installation

## Requirements

| | Version |
|---|---|
| PHP | 8.3 or later (CI runs 8.3, 8.4 and 8.5) |
| Laravel | 13 |
| Database | Any driver Laravel supports; the outbox table is a plain Eloquent table |
| Queue | Any Laravel queue connection, for asynchronous handlers |

## Require the package

```bash
composer require laravents/laravents
```

Package discovery registers `Laravents\LaraventsServiceProvider` and the `Laravents` facade alias; nothing goes into `bootstrap/providers.php` for the package itself.

## Publish the config

```bash
php artisan vendor:publish --tag=laravents-config
```

This writes `config/laravents.php`. Every key and its default is in the [reference](reference.md#configuration). Without the published file the package's defaults apply.

## Run the migration

The package loads its one migration, `create_laravents_event_publications_table`, from its own `database/migrations` directory, so `migrate` creates the outbox table with no publish step:

```bash
php artisan migrate
```

To keep the migration in the application instead (to change the table, or to run it on another connection), publish it. `publishesMigrations()` renames it with today's timestamp, as Laravel's own packages do:

```bash
php artisan vendor:publish --tag=laravents-migrations
```

The table must exist on every database connection a publisher's transaction runs on. The [outbox page](outbox.md#the-connection-a-row-is-written-on) says why and how.

## Autoload the modules

Modules live in `modules/` under the `Modules\` namespace by default. That is the convention for an application; the package itself ships no module. Add the namespace to the application's `composer.json`, next to `App\`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Modules\\": "modules/"
    }
}
```

Then `composer dump-autoload`. The directory and the namespace are configurable (`laravents.paths`, `laravents.modules.namespace`, `laravents.modules.path`); [modules](modules.md#configuration) explains the three keys.

## Create a module

```bash
php artisan laravents:module Band
```

This writes `modules/Band/Api/`, `modules/Band/Handlers/` and `modules/Band/database/migrations/` (each with a `.gitkeep`), the module's service provider `Modules\Band\BandServiceProvider` (it loads the module's migrations), and a first test at `tests/Feature/Modules/BandTest.php`: a PHPUnit class, or a Pest test when the application uses Pest, decided as `make:test` decides (`--pest` and `--phpunit` choose).

The provider is registered through Laravel's own `ServiceProvider::addProviderToBootstrapFile()`, the call `make:provider` makes. Like `make:provider`, it rewrites `bootstrap/providers.php` as a sorted list of fully qualified class names, and comments in that file are dropped. An application that keeps a hand-ordered or commented providers file adds the line itself:

```php
Modules\Band\BandServiceProvider::class,
```

The other generators (`laravents:command`, `laravents:query`, `laravents:event`, `laravents:interceptor`) are on the [console page](console.md#generators).

## The word "module"

In Laravents a module is a namespace: `Modules\Band` is the Band module, `Modules\Band\Api` is what other modules may use. There is no module manifest, no module loader and no runtime registry of modules. `Modules\` is a namespace convention, so it coexists with an application's own package or module system (a `packages/` directory, nwidart/laravel-modules, a monorepo): point `laravents.modules.namespace` and `laravents.paths` at whatever namespace and directories hold the code Laravents should discover.

## Production: the registry cache

On every boot without a cache, Laravents scans `laravents.paths` for handlers, interceptors and `#[Publishes]` models. In production that scan is replaced by a file, as routes are by `route:cache`:

```bash
php artisan laravents:cache
```

`php artisan optimize` runs it for you (Laravents registers a `laravents` task through `optimizes()`), and `php artisan optimize:clear` runs `laravents:clear`. A deploy script that calls `php artisan optimize` needs nothing else. A deploy script that caches config, routes and views one by one (`config:cache`, `route:cache`, ...) and never runs `optimize` must call `laravents:cache` itself; otherwise every request scans.

A handler added after the cache was written is invisible until the next `laravents:cache`, as a route is after `route:cache`. A cache that cannot be built (a duplicate routing key, an attribute where it is not allowed) makes `laravents:cache` exit 1 with the discovery error and leaves no cache file behind, so a broken build never ships a stale registry. The file is `bootstrap/cache/laravents.php`, or `LARAVENTS_CACHE_PATH` (absolute, or relative to the base path).

Proven by `tests/Feature/Console/Cache.pest.php`.

## Check the installation

```bash
php artisan about
```

prints a Laravents section:

```
  Laravents ......................................................................................
  HTTP gateway ............................................................... Enabled, /laravents
  Handlers ....................................................... 4 commands / 1 query / 3 events
  Model publications ........................................................................... 2
  Modules ............................................................... 3 (Band, FanClub, Press)
  Outbox connections ..................................................................... default
  Registry ............................................................................... Scanned
```

`Registry` reads `Cached` once `laravents:cache` has run. When discovery fails, the section shows one `Registry` row with the error and `about` still exits 0; `laravents:verify` is the command that fails on it.

Proven by `tests/Feature/Console/About.pest.php`.

## Next

[Modules](modules.md) explains what a module is and how routing keys are derived; [commands and queries](commands-and-queries.md) sends the first message.
