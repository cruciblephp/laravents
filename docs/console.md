# Console

Every command is under the `laravents:` prefix. The output samples below are the rows the console tests assert, with Laravel's dot leaders folded to one space.

## `laravents:list`

Every discovered handler in one table, in the shape of `route:list`.

| Option | Meaning |
|---|---|
| `--module=` | Only the handlers in this module |

```bash
php artisan laravents:list
php artisan laravents:list --module=Band
```

```
| Kind    | Key                  | Message                           | Handler                                                 | Mode  | Queue    | HTTP                                        |
| Command | band.give_nickname   | Modules\Band\Api\GiveNickname     | Modules\Band\Handlers\BandHandlers@give                 | sync  | -        | POST /laravents/commands/band.give_nickname |
| Command | band.replace_member  | Modules\Band\Api\ReplaceMember    | Modules\Band\Handlers\BandHandlers@replace              | sync  | -        | -                                           |
| Query   | band.get_nickname    | Modules\Band\Api\GetNickname      | Modules\Band\Handlers\BandHandlers@get                  | sync  | -        | GET /laravents/queries/band.get_nickname    |
| Event   | band.*               | -                                 | Modules\FanClub\Handlers\ArchiveBandEvents@__invoke     | sync  | -        | -                                           |
| Command | fan_club.welcome_fan | Modules\FanClub\Api\WelcomeFan    | Modules\FanClub\Handlers\FanClubHandlers@welcome        | sync  | -        | -                                           |
| Event   | -                    | Illuminate\Auth\Events\Registered | Modules\FanClub\Handlers\WelcomeOnRegistered@__invoke   | async | fan_club | -                                           |
| Event   | -                    | Modules\Band\Api\NicknameGiven    | Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke | async | press    | -                                           |
| Command | press.print_headline | Modules\Press\Api\PrintHeadline   | Modules\Press\Handlers\PressHandlers@print              | sync  | -        | -                                           |
```

A pattern handler (`#[EventHandler('band.*')]`) shows its pattern as the key and `-` as the message. Mode is `sync`, `deferred` or `async`; Queue is set for `async` only; HTTP is the gateway route of an `#[Expose]`d handler. When the registry comes from the cache, a line before the table says so and names the file.

Proven by `tests/Feature/Console/ListCommand.pest.php` and `tests/Feature/Http/Expose.pest.php`.

## `laravents:show`

One module's canvas, in the shape of `model:show`: the commands and queries it handles (with their interceptors and HTTP routes), the events it publishes (with each listener, its module and queue, and the models that publish it), the events it consumes (with the publishing module, `Laravel` for a framework event), and the modules it depends on. A pattern handler is listed under every published event whose derived key its pattern covers, and as consuming each such event of another module.

| Argument or option | Meaning |
|---|---|
| `module` | The module name, e.g. `Band` |
| `--json` | One JSON object with the same data |

```bash
php artisan laravents:show Band
php artisan laravents:show Press --json
```

```
Band
Namespace Modules\Band

Commands handler / message / http
band.give_nickname Authorize, Transactional Modules\Band\Handlers\BandHandlers@give / Modules\Band\Api\GiveNickname / POST /laravents/commands/band.give_nickname
band.replace_member Authorize, Transactional Modules\Band\Handlers\BandHandlers@replace / Modules\Band\Api\ReplaceMember / -

Queries handler / message / http
band.get_nickname Modules\Band\Handlers\BandHandlers@get / Modules\Band\Api\GetNickname / GET /laravents/queries/band.get_nickname

Events published listener / module / queue
Modules\Band\Api\MemberReplaced Modules\FanClub\Handlers\ArchiveBandEvents@__invoke / FanClub / sync
Modules\Band\Api\MemberReplaced from Lineup (created)
Modules\Band\Api\NicknameGiven Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke / Press / press
Modules\Band\Api\NicknameGiven Modules\FanClub\Handlers\ArchiveBandEvents@__invoke / FanClub / sync
Modules\Band\Api\NicknameGiven from Nickname (created)

Events consumed handler / module / queue

Depends on classes
```

An unknown module exits 1: `Module [Billing] not found. Known modules: Band, FanClub, Press.` Depends on counts, per other module, the classes of this one that reference it, and marks those that reach outside its Api (`Orders 1 class / 1 outside Api`), the same graph `laravents:verify` checks.

Proven by `tests/Feature/Console/ShowCommand.pest.php`.

## `laravents:verify`

Checks the module boundaries: dependency direction, Api purity, cycles, and that the registry builds. Exit 1 on any violation, for CI. [Verification and docs](verification-and-docs.md) explains each rule.

| Option | Meaning |
|---|---|
| `--json` | `{modules, graph, violations, passed}`, each violation with its rule, severity, message, class, used class, file and line |
| `--allow-cycles` | Report cycles as warnings instead of failures |

```bash
php artisan laravents:verify
php artisan laravents:verify --json
```

```
Module graph 3 modules
FanClub
Press -> Band
INFO 3 modules verified, no violations.
```

A violation names the class, what it uses, and where:

```
Module graph 2 modules
Billing -> Orders
Direction 1 violation
ERROR Laravents\Tests\Fixtures\Verify\Direction\Billing\Handlers\ChargeOnOrderPlaced uses Laravents\Tests\Fixtures\Verify\Direction\Orders\Models\Order (tests/Fixtures/Verify/Direction/Billing/Handlers/ChargeOnOrderPlaced.php:15).
```

Proven by `tests/Feature/Console/VerifyCommand.pest.php`.

## `laravents:docs`

Writes `README.md` (the module table and a Mermaid dependency graph) and one `<Module>.md` per module, from the data `laravents:show` prints. Files whose content is unchanged are not rewritten. The example application's pages are [docs/laravents](laravents/README.md).

| Option | Meaning |
|---|---|
| `--path=` | The directory to write into, under the base path; default `laravents.docs.path` (`docs/laravents`) |
| `--realpath` | The `--path` given is already absolute, as `migrate --realpath` takes it |
| `--check` | Write nothing; exit 1 when a file is stale or missing |

```bash
php artisan laravents:docs
php artisan laravents:docs --check
```

A write prints one row per file, `written` or `unchanged`, then `INFO Module docs for 2 modules written to [docs/laravents].` A check prints `current`, `stale` or `missing` per file, then `INFO Module docs in [docs/laravents] are current.` or `ERROR 1 files in [docs/laravents] are out of date. Run php artisan laravents:docs.` and exits 1. A check never creates the directory.

Proven by `tests/Feature/Console/DocsCommand.pest.php`.

## `laravents:cache` and `laravents:clear`

`laravents:cache` writes the handler registry to `bootstrap/cache/laravents.php` (`LARAVENTS_CACHE_PATH`), and boot then reads it instead of scanning; `laravents:clear` deletes it. `php artisan optimize` and `optimize:clear` run them.

```bash
php artisan laravents:cache
php artisan laravents:clear
```

```
INFO Laravents registry cached successfully.
INFO Laravents registry cache cleared successfully.
```

`laravents:cache` clears first, so a failing build (a discovery error, printed with its location) exits 1 and leaves no cache at all. Before writing, it reads the registry back from the file's data and compares it with the scan; a registry that would not come back as it was scanned (a module manifest whose constructor parameters are not all public properties) is not written either, and the command exits 1. [Installation](installation.md#production-the-registry-cache) has the deployment side and `laravents.cache.stale`.

`laravents:list` and `laravents:show` say `Registry loaded from the cache` only when the registry in use came from the file, not when a stale file was set aside.

Proven by `tests/Feature/Console/Cache.pest.php`.

## `laravents:republish`

Re-dispatches outbox rows that were never picked up: incomplete, and never dispatched or dispatched longer ago than `laravents.outbox.republish_after`, on every connection in `laravents.outbox.connections`. Each row is claimed atomically first.

| Option | Meaning |
|---|---|
| `--dry-run` | List the rows that would be republished (publication, connection, listener, published, dispatched), without claiming them |

```bash
php artisan laravents:republish --dry-run
php artisan laravents:republish
```

```
INFO 2 publications would be republished.
INFO 2 publications republished.
```

A job that ran and failed is in `failed_jobs`; bring it back with `queue:retry`. [The outbox](outbox.md#republishing) explains when to schedule it.

Proven by `tests/Feature/Outbox/Republish.pest.php`.

## Generators

The generators are Laravel's `GeneratorCommand` re-rooted at the modules directory (`laravents.modules.path`, namespace `laravents.modules.namespace`). Without `--force`, a generator whose target file exists prints `... already exists.` and writes nothing; a message generator checks the message and its handler before writing either.

| Command | Writes | Options |
|---|---|---|
| `laravents:module Band` | `Api/`, `Handlers/`, `database/migrations/`, `BandServiceProvider` (registered in `bootstrap/providers.php`), `tests/Feature/Modules/BandTest.php` (PHPUnit, or Pest when the application uses it, as `make:test` decides) | `--force`, `--pest`, `--phpunit` |
| `laravents:command GiveNickname --module=Band` | `Modules\Band\Api\GiveNickname` (`Sendable`) and `Modules\Band\Handlers\GiveNicknameHandler` | `--authorize`, `--transactional`, `--force` |
| `laravents:query GetNickname --module=Band` | `Modules\Band\Api\GetNickname` (`Askable`) and `GetNicknameHandler` | `--authorize`, `--force` |
| `laravents:event NicknameGiven --module=Band` | `Modules\Band\Api\NicknameGiven` (`Publishable`) and `NicknameGivenHandler` | `--async` (queue named after the module), `--async=press`, `--force` |
| `laravents:interceptor RateLimit --module=Band` | `Modules\Band\Interceptors\RateLimit`, and the pointcut attribute `Modules\Band\Attributes\RateLimit` when it does not exist | `--around` (default), `--before`, `--after`, `--pointcut=`, `--force` |

```bash
php artisan laravents:module Band
php artisan laravents:command GiveNickname --module=Band --authorize --transactional
php artisan laravents:event NicknameGiven --module=Band --async=press
php artisan laravents:interceptor RateLimit --module=Band --before --pointcut=RateLimited
```

`-m` is short for `--module`, `-f` for `--force`, `-p` for `--pointcut`. The message and interceptor generators refuse a module that does not exist: `Module [Band] does not exist. Create it with: php artisan laravents:module Band`. `--pointcut` takes an existing class or interface as it is; otherwise it names an attribute in the module's `Attributes` namespace, written when its file does not exist and never overwritten. Without `--pointcut` the attribute takes the interceptor's name, and the interceptor imports it as `RateLimitPointcut`, since a class cannot import its own name. `laravents:module` registers the provider with `addProviderToBootstrapFile()`, which rewrites `bootstrap/providers.php` sorted and without comments, as `make:provider` does ([installation](installation.md#create-a-module)).

The generated code passes Pint, Rector and PHPStan at level max as it comes out.

### Stubs

```bash
php artisan vendor:publish --tag=laravents-stubs
```

copies the stubs into `stubs/laravents/`, where a stub wins over the package's: `command`, `query`, `event`, `command-handler`, `query-handler`, `event-handler`, `interceptor.around`, `interceptor.before`, `interceptor.after`, `pointcut`, `provider`, `module-test`, `module-test.pest`.

Proven by `tests/Feature/Console/Generators.pest.php`.

## Publishing

| Tag | Publishes |
|---|---|
| `laravents-config` | `config/laravents.php` |
| `laravents-migrations` | The outbox migration, renamed with the current timestamp |
| `laravents-stubs` | The generator stubs, into `stubs/laravents/` |

Proven by `tests/Feature/Console/PublishMigrations.pest.php`.

## `about`

`php artisan about` has a Laravents section: the registry (`Scanned` or `Cached`), the modules, the handlers by kind, the model publications, the HTTP gateway and the outbox connections, and the disabled modules when `laravents.modules.disabled` names any. `about --json` puts them under `laravents`. A registry that cannot be built is one error row, and `about` still exits 0. [Installation](installation.md#check-the-installation) shows the output.

Proven by `tests/Feature/Console/About.pest.php`.

## In CI

Two commands fail the build when the modules break their boundaries or the generated docs drift:

```bash
php artisan laravents:verify
php artisan laravents:docs --check
```

Run them after `composer install`, in the same job as the tests. `laravents:verify --json` gives one entry per violation with its file and line, for annotations.
