# Modules

## A module is a namespace

A module is the namespace segment directly under the modules root. With the default root `Modules`, every class in `Modules\Band\...` belongs to the Band module, every class in `Modules\Press\...` to Press, and every class in `Modules\FanClub\...` to FanClub. Nothing registers a module: a class's namespace is all Laravents reads.

```
modules/
    Band/
        Api/                 public: GiveNickname, GetNickname, NicknameView, NicknameGiven,
                                     ReplaceMember, MemberReplaced, Instrument
        Handlers/            internal: BandHandlers
        Models/              internal: Nickname, Lineup
        database/migrations/
        BandServiceProvider.php
    Press/
        Api/                 public: PrintHeadline
        Handlers/            internal: PressHandlers, HeadlineOnNicknameGiven
        Models/              internal: Headline
    FanClub/
        Api/                 public: WelcomeFan
        Handlers/            internal: FanClubHandlers, WelcomeOnRegistered, ArchiveBandEvents
        Models/              internal: Welcome
```

This is the example application under `workbench/modules/`, which the test suite runs.

## Api is public, everything else is internal

`Modules\Band\Api` is the module's public surface: its commands, queries, query results and events. Everything else under `Modules\Band` (handlers, models, services, migrations) is internal. Another module, or any code outside the modules (`App\...`, a local package), may reference `Modules\Band\Api\*` and nothing else of Band.

Two more rules keep the boundary honest:

- **Api purity.** A class in `Modules\Band\Api` references nothing of Band outside the Api. That is why the example's `NicknameGiven::fromModel()` takes Eloquent's `Model` and not `Modules\Band\Models\Nickname`: a public event that named an internal model would make every listener depend on it.
- **No cycles.** Press depends on Band; Band must not depend on Press, through any namespace.

Nothing stops PHP from crossing these lines at run time. `php artisan laravents:verify` checks them over the source; [verification and docs](verification-and-docs.md) explains what it reads and what it cannot see.

A module talks to another by sending its commands, asking its queries and listening to its events, never by calling its classes. Press learns that a nickname was given from `NicknameGiven` and never reads the `nicknames` table.

## A shared module

Some types belong to no single module: money, a currency, an address, an id. In domain-driven design that is a shared kernel. In Laravents it is an ordinary module, `Modules\Shared`, whose `Api` holds those types; the rules above already allow it and need no setting:

```
modules/
    Shared/
        Api/                 public: Money, Currency
    Orders/
        Api/                 public: OrderPlaced (carries a Shared\Api\Money)
    Billing/
        Handlers/            internal: ChargeOnOrderPlaced (uses Orders\Api and Shared\Api)
```

- **Any module may use it.** `Shared\Api` is a module's Api like any other, so Orders' event can carry a `Money` and Billing can read it.
- **Its Api stands alone.** Api purity holds for it too: `Money` may use `Currency`, but not a class under `Shared\Support`. A helper that every module needs is part of the Api, or it is not shared.
- **It depends on nobody.** A shared module that listens to an event of a module that uses it is a cycle, and `laravents:verify` fails it. Keep it to types, with no handlers that reach back.
- **Keep it small.** Everything in it is a dependency of every module that uses it; a type that only two modules share can live in one of them, in its Api.

**The user is the application's, not a module's.** Laravel already gives every module the user: `#[CurrentUser] Authenticatable $actor` in a handler, the `Authenticatable` contract in a type, and `App\Models\User`, which is outside the modules root, so referencing it crosses no module boundary. A `Users` module that owns the users table works too, through its `Api`: a query such as `users.get` returning a `UserView`, never its model.

Proven by `tests/Feature/Console/VerifyCommand.pest.php` ("a shared kernel module passes" and "a shared kernel whose Api leans on its own internals"), over `tests/Fixtures/Verify/Kernel` and `KernelLeak`.

## Shared code and local packages

Not every shared class is a domain type. Where it goes depends on who uses it:

| The code | Where it goes |
|---|---|
| A type several modules share: money, an address, an id | A shared module's `Api` ([above](#a-shared-module)) |
| A helper with no domain: formatting, a clock, a string or array routine | A class in `App\Support`, with static methods in the shape of Laravel's `Str`, `Arr` and `Number` |
| Code another application will use too | A local package in `packages/`, with its own `composer.json`, required through a Composer path repository |

- **Classes, not functions.** A global helper function (a `helpers.php` loaded through composer's `files`) is loaded on every request and is a blind spot of `verify`, which reads class references only ([verification and docs](verification-and-docs.md#the-scanner)). A namespaced function is no different.
- **Support code uses modules through their Api only.** `App\Support` sits outside the modules, and `verify` reads `app/` with no setting: a support class that imports a module's model fails the direction rule, as another module would. A helper that needs a module's internals belongs in that module.
- **A package is for reuse, not for organizing one application.** Inside one application, namespaces and modules do that job; Symfony's best practices say the same of bundles. A package earns its own `composer.json` when a second application requires it. Give it an organization namespace (`Acme\Money`) so it can be moved out of the repository unchanged, and add its directory to `laravents.paths` to have `verify` check how it uses the modules.

```json
"repositories": [
    {"type": "path", "url": "packages/*"}
],
"require": {
    "acme/money": "@dev"
}
```

`@dev` because a path package has no tagged release, and Laravel's `minimum-stability` is `stable`: `"*"` fails to resolve.

Proven by `tests/Feature/Console/VerifyCommand.pest.php` ("a class outside every module, in app/ or another scanned path, reaches a module only through its Api"), over `tests/Fixtures/Verify/Outside`.

## Routing keys

Every command and query has a routing key, a string such as `band.give_nickname`. The key is what the HTTP gateway serves, what `laravents:list` prints, what a Gate ability is named after, and what a caller may send instead of an object.

The key is derived from the message class: the module in snake case, a dot, then the class name in snake case with the module's words removed once.

| Message class | Routing key |
|---|---|
| `Modules\Band\Api\GiveNickname` | `band.give_nickname` |
| `Modules\Band\Api\GetNickname` | `band.get_nickname` |
| `Modules\Chat\Api\SendMessage` | `chat.send_message` |
| `Modules\UserProfile\Api\UpdateUserProfile` | `user_profile.update` |
| `Modules\Chat\Api\MergeChatIntoChat` | `chat.merge_into_chat` |
| `Modules\Chat\Api\Chat` | `chat.chat` |
| `Modules\Press\Api\PrintHeadline` | `press.print_headline` |

A class outside the root (`App\Jobs\GiveNickname`) or directly in it (`Modules\GiveNickname`) has no derived key.

Events are matched by type, not by key: a handler of `NicknameGiven` receives every `NicknameGiven`, and subclasses or implementors of the type it declares. A key is only used for an event when the handler asks for one (below).

Proven by `tests/Unit/RoutingKey.pest.php` and `tests/Feature/Discovery/EventMatching.pest.php`.

## The explicit key

A handler attribute takes the key as its argument, and an explicit key wins over the derived one. The key is required when the message class is not in a module: discovery refuses an unkeyed `#[CommandHandler]` whose message is `stdClass` with `cannot derive a routing key from [stdClass] because it is not in a module under [Modules]; pass the key explicitly`.

A keyed handler may also take no message class at all. Its first parameter is then an `array` of fields, and callers send it by key with an array payload:

```php
/** @param array{email: string, rollback?: bool} $payload */
#[CommandHandler('mail.sign_up')]
#[Transactional]
public function signUp(array $payload, EventBus $events): void
```

From [`tests/Fixtures/Deferred/Mail/Handlers/SignUpHandlers.php`](../tests/Fixtures/Deferred/Mail/Handlers/SignUpHandlers.php). A keyed `#[EventHandler('ledger.imported')]` is reached only by `Laravents::publish('ledger.imported', [...])`, never by an object's type.

Keys are unique per kind: two command handlers of `band.give_nickname`, or two command handlers of the same message class, fail discovery and name both files and lines. A command key and a query key may be the same string.

Proven by `tests/Feature/Discovery/Validation.pest.php`.

## Queues

An `#[Asynchronous]` event handler runs on a queue named after its own module, in snake case: a handler in `Modules\Band\Handlers` queues on `band`, one in `Modules\UserProfile\Handlers` on `user_profile`. A handler outside every module queues on `default`. The attribute's argument names the queue instead:

```php
#[EventHandler]
#[Asynchronous('press')]
public function __invoke(NicknameGiven $event, CommandBus $commands, #[Header('publication_id')] string $publicationId): void
```

From [`workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php`](../workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php).

The queue connection is the application's default, or `laravents.async.connection`. A worker must listen on the queue: `php artisan queue:work --queue=press,fan_club,default`.

Proven by `tests/Feature/Discovery/EventMatching.pest.php`.

## Where handlers are found

Laravents discovers classes in the directories of `laravents.paths` and reads their attributes: `#[CommandHandler]`, `#[QueryHandler]` and `#[EventHandler]` on methods, `#[Before]`, `#[Around]` and `#[After]` on interceptor methods, `#[Publishes]` on models. A handler is any public, non-static method with one of the handler attributes, on any class; one class may hold several handlers (`BandHandlers` holds `give`, `replace` and `get`), or one invokable handler (`HeadlineOnNicknameGiven`).

Discovery fails loudly, at boot during development and at `laravents:cache` in production, when an attribute is misused: two handler attributes on one method, `#[Authorize]` or `#[Expose]` on an event handler, `#[Asynchronous]` or `#[Deferred]` on a command or query handler, both modes on one handler, a static handler, an interceptor with an unknown pointcut. The message names the class, method, file and line. The [reference](reference.md#exceptions) lists every `DiscoveryException`.

## Configuration

| Key | Default | What it is |
|---|---|---|
| `laravents.paths` | `[base_path('modules')]` | Directories scanned for handlers, interceptors and `#[Publishes]` models. Add a directory to discover classes outside the modules, such as `app/Listeners` for handlers of framework events. |
| `laravents.modules.namespace` | `'Modules'` | The modules root. The segment under it is the module; routing keys and default queues come from it. |
| `laravents.modules.path` | `base_path('modules')` | The directory that namespace maps to in `composer.json`. The generators write here. |
| `laravents.modules.disabled` | `[]` | Modules left out of discovery. See [turning a module off](#turning-a-module-off). |
| `laravents.modules.manifest` | `null` | The class each module's `module.json` is hydrated into, or `null` to read none. See [a module's manifest](#a-modules-manifest). |
| `laravents.modules.manifest_required` | `false` | Whether a module with no `module.json` fails discovery. |

The first three are independent: `paths` is a discovery list (several directories, inside or outside the modules), `modules.namespace` decides what a module is, and `modules.path` is the one directory the generators write into. An application that keeps its modules in `src/Domain` under `Acme\Domain\` sets `paths` to `[base_path('src/Domain')]`, `modules.namespace` to `'Acme\Domain'` and `modules.path` to `base_path('src/Domain')`. The example application sets `paths` and `modules.path` to its `workbench/modules` directory in [`workbench/app/Providers/WorkbenchServiceProvider.php`](../workbench/app/Providers/WorkbenchServiceProvider.php).

## Turning a module off

A module named in `laravents.modules.disabled` is left out of discovery: its handlers, interceptors and `#[Publishes]` models are not registered. Its commands and queries are unknown routing keys, its listeners receive nothing, and an event it used to consume is published to the modules that remain. This is the switch for running a new module next to the one it replaces and cutting over without a deploy of code: list the old one, then delete it.

The list is the application's to compute, as any config value is, from the environment or a feature flag:

```php
'disabled' => array_filter(explode(',', (string) env('LARAVENTS_DISABLED_MODULES'))),
```

- Only discovery changes. The module's service provider still loads, so its Gate abilities, routes and migrations stay; remove it from `bootstrap/providers.php` too to take the module out entirely.
- The registry cache holds the list it was built with, as `config:cache` holds a config value: after a change, run `laravents:cache` (or `php artisan optimize`) again.
- `laravents:verify` still checks every module, the disabled ones too, so code that is switched off cannot rot past the module rules.
- A name that is not a module disables nothing.
- `php artisan about` names the disabled modules. `laravents:list` leaves their handlers out, and `laravents:show` and `laravents:docs` show such a module with no handlers, since they read the registry.

Proven by `tests/Feature/Discovery/DisabledModules.pest.php` and `tests/Feature/Console/About.pest.php`.

## A module's manifest

A module may describe itself in a `module.json` in its directory, data any tool can read ([`workbench/modules/Band/module.json`](../workbench/modules/Band/module.json)):

```json
{
    "version": "1.0.0",
    "description": "The band: its line-up and the nicknames its members give"
}
```

What a manifest says is the application's to decide, not Laravents': `laravents.modules.manifest` names a class, and its constructor is the schema ([`workbench/app/Support/ModuleManifest.php`](../workbench/app/Support/ModuleManifest.php)):

```php
final readonly class ModuleManifest
{
    public function __construct(
        public string $version,
        public string $description = '',
    ) {}
}
```

Discovery hydrates each `module.json` into that class with the `PayloadConverter` that builds every message, so PHP reads it typed and no caller narrows a field:

```php
Laravents::manifest('Band', ModuleManifest::class)?->version;  // '1.0.0'
Laravents::manifest('Press', ModuleManifest::class);           // null: Press has no module.json
Laravents::modules();                                          // ['Band' => Module, 'FanClub' => Module, 'Press' => Module]
Laravents::module('Billing');                                  // null: no such module
```

- **The class is the schema, enforced at discovery.** A field the class does not take, a field of the wrong type, a required field missing, or a file that is not a JSON object fails discovery naming the file and why (`.../Band/module.json has [colour], which ModuleManifest does not take.`): at boot during development, at `laravents:cache`, and in `laravents:verify`. Types are held as `strict_types` holds them: `2` is not a `string`. JSON has one number type, so `1` is a `float` and `1.0` an `int`, as JSON Schema reads them; `1.5` is not an `int`.
- **`manifest_required`** makes a module with no `module.json` fail as well. Without it, such a module has no manifest.
- **Every module is listed**, by name and sorted, the disabled ones too, as `laravents:verify` sees them. A `Module` carries `name`, `namespace`, `disabled` and `manifest`.
- **The manifests are part of the registry**, so `laravents:cache` caches them and a cached boot reads no JSON. They are not messages: `Laravents::fake()` leaves them as they are.
- **`Laravents::manifest()` is typed as the class asked for.** Asking for another class than `laravents.modules.manifest` is a mistake in the caller, and throws a `LogicException`.

Proven by `tests/Feature/Discovery/ModuleList.pest.php`.

## Seeing the modules

`php artisan laravents:list` prints every handler in one table, `php artisan laravents:show Band` one module's canvas, and `php artisan laravents:docs` writes a Markdown page per module with a dependency graph. The [console page](console.md) has every command and its output; the example's generated pages are in [docs/laravents](laravents/README.md).
