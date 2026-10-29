# Laravents

**Eloquent for events. Modules for Laravel.**

What Eloquent is to your database, Laravents is to your messages: commands, queries and events with conventions, attributes and an outbox, so modules talk through a public API and never through each other's models.

It is built on Laravel's own pieces: the Bus dispatcher and Pipeline for dispatch, Context for metadata, the queue for asynchronous handlers, Gate for authorization, the container for handlers and their dependencies.

This README is the thirty-second example. The manual, from installation to the reference, is in [docs/](docs/README.md).

The Band, Press and FanClub modules quoted here live in [`workbench/`](workbench/README.md), the example application the tests and this documentation use: a Beatles fan-club site, built by Luciano, a fan and a developer. Nothing in it is installed with the package: `composer require laravents/laravents` ships `src/`, `config/`, `database/`, `routes/` and `stubs/` only. `vendor/bin/testbench serve` runs it.

## Installation

```bash
composer require laravents/laravents
```

The service provider and the `Laravents` facade are registered by package discovery. Publish the config and run the outbox migration:

```bash
php artisan vendor:publish --tag=laravents-config
php artisan migrate
```

Modules live in `modules/` under the `Modules\` namespace (`laravents.paths`, `laravents.modules.namespace` and `laravents.modules.path` in `config/laravents.php`). Autoload that directory in your application's `composer.json`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Modules\\": "modules/"
    }
}
```

Create a module; this also registers its service provider in `bootstrap/providers.php`:

```bash
php artisan laravents:module Band
php artisan laravents:command GiveNickname --module=Band
```

A module is a namespace: `Modules\Band\Api` is what other modules may use, everything else in `Modules\Band` is its own. A module may describe itself in a `module.json`, hydrated into a class the application names, so `Laravents::manifest('Band', ModuleManifest::class)?->version` reads it typed and a file that does not fit fails discovery. [Installation](docs/installation.md) and [modules](docs/modules.md) in the manual have the details.

## The thirty-second example

Every sample below comes from the example application. A command is a plain object with `Sendable` ([`workbench/modules/Band/Api/GiveNickname.php`](workbench/modules/Band/Api/GiveNickname.php)). Its routing key, `band.give_nickname`, comes from its module and its name.

```php
namespace Modules\Band\Api;

use Laravents\Concerns\Sendable;
use Laravents\Contracts\ValidatesPayload;

/**
 * Command band.give_nickname: the actor gives another member a nickname.
 */
final readonly class GiveNickname implements ValidatesPayload
{
    use Sendable;

    public function __construct(public int $memberId, public string $nickname) {}

    public static function rules(): array
    {
        return [
            'memberId' => 'required|integer|min:1',
            'nickname' => 'required|string|max:40',
        ];
    }
}
```

Its handler is found by attribute ([`workbench/modules/Band/Handlers/BandHandlers.php`](workbench/modules/Band/Handlers/BandHandlers.php)). `#[Authorize]` asks the Gate (`Gate::define('band.give_nickname', ...)` in the module's provider: only a member gives nicknames, so Luciano, a fan, gets a 403), `#[Transactional]` wraps it in a database transaction, `#[Expose]` serves it at `POST /laravents/commands/band.give_nickname`.

```php
final class BandHandlers
{
    #[CommandHandler]
    #[Authorize]
    #[Transactional]
    #[Expose]
    public function give(GiveNickname $command, #[CurrentUser] Authenticatable $actor): int
    {
        // Creating the nickname publishes NicknameGiven (#[Publishes] on the model), inside this command's transaction.
        return Nickname::query()->create([
            'given_by' => $actor->getAuthIdentifier(),
            'member_id' => $command->memberId,
            'nickname' => $command->nickname,
        ])->id;
    }
}
```

The model publishes the event when it is created ([`workbench/modules/Band/Models/Nickname.php`](workbench/modules/Band/Models/Nickname.php)), and the event says how it is built from the model ([`workbench/modules/Band/Api/NicknameGiven.php`](workbench/modules/Band/Api/NicknameGiven.php)):

```php
#[Publishes(NicknameGiven::class, on: 'created')]
final class Nickname extends Model
{
    protected $fillable = ['given_by', 'member_id', 'nickname'];
}
```

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

    /** Takes Eloquent's Model, not Nickname, so the Api references nothing of the module outside it. */
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
}
```

Another module, Press, prints the headline on the queue ([`workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php`](workbench/modules/Press/Handlers/HeadlineOnNicknameGiven.php)). The outbox row is written in the command's transaction, so the headline is printed if and only if the nickname was committed:

```php
final class HeadlineOnNicknameGiven
{
    #[EventHandler]
    #[Asynchronous('press')]
    public function __invoke(NicknameGiven $event, CommandBus $commands, #[Header('publication_id')] string $publicationId): void
    {
        $commands->dispatch(new PrintHeadline($event->nicknameId, $event->memberId, $publicationId));
    }
}
```

The row stores the event as Laravel's queue stores a job, serialized inside JSON, so it fits a text column on every driver; as for a job's, a binary property must be encoded by the event (base64) before publishing.

Sending it is one line, by object or by key:

```php
$id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

$id = Laravents::command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']);
```

## Testing

`Laravents::fake()` works like `Event::fake()` ([`tests/Feature/Testing/LaraventsFake.pest.php`](tests/Feature/Testing/LaraventsFake.pest.php)):

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

Fake only some messages and let the rest run for real:

```php
$fake = Laravents::fake([NicknameGiven::class]);

$id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

$fake->assertPublished(NicknameGiven::class, fn (NicknameGiven $event): bool => $event->nicknameId === $id && $event->memberId === 2);
$fake->assertPublishedTimes(NicknameGiven::class, 1);
$fake->assertNotSent(GiveNickname::class);
```

`except([...])`, `returns()` for commands and `answers()` for queries are there too, as are the `assertSent`, `assertAsked` and `assertPublished` families with their `Not`, `Nothing` and `Times` variants. [Testing](docs/testing.md) in the manual has all of them.

## Console

| Command | What it does |
|---|---|
| `laravents:list [--module=]` | Every handler: kind, routing key, message, handler, mode, queue, HTTP route |
| `laravents:show <Module> [--json]` | One module's canvas: commands, queries, events published and consumed, model publications, HTTP endpoints, dependencies |
| `laravents:verify [--json] [--allow-cycles]` | Module direction, Api purity, cycles and the registry; exit 1 on a violation |
| `laravents:docs [--path=] [--realpath] [--check]` | A Markdown page per module and a Mermaid dependency graph; `--check` exits 1 when they are stale |
| `laravents:cache` / `laravents:clear` | Cache the handler registry (part of `php artisan optimize` and `optimize:clear`) |
| `laravents:republish [--dry-run]` | Re-dispatch outbox rows that were never picked up |
| `laravents:module`, `laravents:command`, `laravents:query`, `laravents:event`, `laravents:interceptor` | Generators, on Laravel's `GeneratorCommand`; stubs publishable under `laravents-stubs` |

`php artisan about` has a Laravents section. In CI, run:

```bash
php artisan laravents:verify
php artisan laravents:docs --check
```

The example's generated documentation is [docs/laravents/README.md](docs/laravents/README.md). Every command and its options are on the manual's [console page](docs/console.md).

## Who the actor is

- The actor of a message is the user on the guard when it is sent; the headers carry `actor_id` and `actor_guard` as strings.
- `#[Authorize]` trusts only a user the named guard holds; a header alone authorizes nothing.
- An asynchronous handler runs as its publication's actor: the job rebuilds the user through the guard's `UserProvider` and puts the guard back afterwards.
- A plain Laravel job does not inherit the actor through Context; only the correlation and causation ids travel.
- `Laravents::as($user)` puts the user on the guard for one dispatch and adds no other trust path.

[Metadata and the actor](docs/metadata-and-actor.md) in the manual explains each rule.

## Scope

What 1.0 deliberately leaves out:

- Custom headers are not inherited by nested messages; only the five metadata keys are.
- Commands are always synchronous; only event handlers can be asynchronous.
- `rules()` runs at the HTTP edge and in `fromRequest()`, not on every bus dispatch.
- An external `#[EventHandler]` of a parent class is not reached for its subclasses.
- No outbound bridge: Laravents events are not dispatched to Laravel's event dispatcher.
- Eloquent's string model events (`eloquent.created: ...`) are not bridged; `#[Publishes]` on the model is the way to publish on one.
- A transaction opened with raw SQL (`DB::unprepared('BEGIN')`) is invisible to the outbox, whose row then goes on the default connection.
- `laravents:docs` does not delete the page of a module that no longer exists.

The example modules, Band, Press and FanClub, are kept for this documentation: between them they use every feature.

The test suite runs on SQLite. The outbox payload format is built so PostgreSQL accepts it too (no NUL bytes, valid UTF-8, asserted by [`tests/Feature/Outbox/Payload.pest.php`](tests/Feature/Outbox/Payload.pest.php)); no PostgreSQL job runs in CI.

## Development

```bash
composer install
composer check
vendor/bin/testbench serve
```

`composer check` runs Composer's strict PSR-4 check, Pint, Rector, PHPStan at level max and the test suite (Crucible on Orchestra Testbench). The example application is in `workbench/`.

Coverage and mutation testing need a coverage driver. pcov is the fast one:

```bash
pecl install pcov
vendor/bin/crucible --coverage
vendor/bin/crucible mutate
```

CI collects coverage with pcov on PHP 8.5 and uploads the results to code scanning as SARIF. How a change is judged is in [CONTRIBUTING.md](CONTRIBUTING.md), how to report a vulnerability in [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
