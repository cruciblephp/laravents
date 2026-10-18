<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Foundation\PackageManifest;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\Registry;
use Laravents\Facades\Laravents;
use Laravents\LaraventsServiceProvider;
use Modules\Band\Handlers\BandHandlers;
use Modules\FanClub\Handlers\ArchiveBandEvents;
use Modules\FanClub\Handlers\FanClubHandlers;
use Modules\FanClub\Handlers\WelcomeOnRegistered;
use Modules\Press\Handlers\HeadlineOnNicknameGiven;
use Modules\Press\Handlers\PressHandlers;
use Orchestra\Testbench\Workbench\Workbench;
use Workbench\App\Models\User;

use function Orchestra\Testbench\workbench_path;

test('the application boots and serves the home page', function (): void {
    $this->get('/')->assertOk();
});

test('the Laravents service provider is registered and merges its config', function (): void {
    expect(app()->getProvider(LaraventsServiceProvider::class))->toBeInstanceOf(LaraventsServiceProvider::class)
        ->and(config('laravents.paths'))->toBe([workbench_path('modules')])
        ->and(config('laravents.async.tries'))->toBe(3)
        ->and(config('laravents.outbox.republish_after'))->toBe(300);
});

test('the service provider and the Laravents alias come from package discovery of composer.json, not from a providers list', function (): void {
    /** @var array{extra: array{laravel: array{providers: list<string>, aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest = app(PackageManifest::class);
    $listed = Workbench::configuration()->getExtraAttributes()['providers'];

    expect($composer['extra']['laravel'])->toBe([
        'providers' => [LaraventsServiceProvider::class],
        'aliases' => ['Laravents' => Laravents::class],
    ])
        ->and($manifest->config('providers'))->toContain(LaraventsServiceProvider::class)
        ->and($manifest->aliases())->toMatchArray(['Laravents' => Laravents::class])
        ->and($listed)->not->toContain(LaraventsServiceProvider::class)
        ->and(app()->getProvider(LaraventsServiceProvider::class))->toBeInstanceOf(LaraventsServiceProvider::class)
        ->and(class_exists('Laravents'))->toBeTrue()
        ->and((new ReflectionClass('Laravents'))->getName())->toBe(Laravents::class)
        ->and(\Laravents::isFake())->toBeFalse();
});

test('the Modules namespace is autoloaded from workbench/modules/', function (): void {
    $loader = ClassLoader::getRegisteredLoaders()[dirname(__DIR__, 2).'/vendor'];
    $directories = $loader->getPrefixesPsr4()['Modules\\'] ?? [];

    expect(array_map(realpath(...), $directories))->toBe([realpath(workbench_path('modules'))]);
});

test('Testbench boots the workbench: the registry holds exactly its modules\' handlers and its User is the auth model', function (): void {
    $handlers = array_map(
        static fn (HandlerDefinition $handler): string => $handler->class.'::'.$handler->method,
        app(Registry::class)->all(),
    );
    sort($handlers);

    $provider = auth()->createUserProvider('users');

    expect($handlers)->toBe([
        BandHandlers::class.'::get',
        BandHandlers::class.'::give',
        BandHandlers::class.'::replace',
        ArchiveBandEvents::class.'::__invoke',
        FanClubHandlers::class.'::welcome',
        WelcomeOnRegistered::class.'::__invoke',
        HeadlineOnNicknameGiven::class.'::__invoke',
        PressHandlers::class.'::print',
    ])
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and($provider)->toBeInstanceOf(EloquentUserProvider::class)
        ->and($provider instanceof EloquentUserProvider ? $provider->getModel() : null)->toBe(User::class);
});
