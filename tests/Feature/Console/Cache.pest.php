<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravents\Discovery\AppliedInterceptor;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Discovery\RegistryCache;
use Laravents\Interceptors\AuthorizationInterceptor;
use Laravents\Interceptors\TransactionInterceptor;
use Laravents\LaraventsServiceProvider;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Limited;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\AuditedHandlers;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\DeadlockHandlers;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\HappenedListeners;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\LabHandlers;
use Laravents\Tests\Fixtures\Interception\Lab\Interceptors\LabInterceptors;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

afterEach(function (): void {
    if (is_file(cachePath())) {
        unlink(cachePath());
    }
});

function cachePath(): string
{
    return app()->bootstrapPath('cache/laravents.php');
}

/**
 * A directory with no classes in it, for laravents.paths.
 *
 * @return list<string>
 */
function emptyPaths(): array
{
    $path = sys_get_temp_dir().'/laravents-empty';

    if (! is_dir($path)) {
        mkdir($path);
    }

    return [$path];
}

/** A registry resolved as the next boot would: the singleton forgotten, so the provider's closure runs again. */
function freshRegistry(): Registry
{
    app()->forgetInstance(Registry::class);

    return app(Registry::class);
}

test('a registry survives serialize() unchanged, pointcut attribute instances included', function (): void {
    $built = (new RegistryBuilder('Laravents\Tests\Fixtures\Interception'))->build([
        LabHandlers::class,
        AuditedHandlers::class,
        DeadlockHandlers::class,
        HappenedListeners::class,
        LabInterceptors::class,
    ]);

    $restored = unserialize(serialize($built));
    expect($restored)->toBeInstanceOf(Registry::class)->toEqual($built);

    /** @var Registry $restored */
    $look = $restored->queryHandler('lab.look');
    expect($look)->not->toBeNull();

    $limits = array_values(array_filter(
        $restored->interceptorsFor($look),
        static fn (AppliedInterceptor $applied): bool => $applied->attribute instanceof Limited,
    ));

    expect($limits)->toHaveCount(1)
        ->and($limits[0]->attribute)->toEqual(new Limited(max: 3));

    $attribute = $limits[0]->attribute;
    expect($attribute instanceof Limited ? $attribute->max : null)->toBe(3);
});

test('laravents:cache writes one file the next boot loads instead of scanning', function (): void {
    expect(Artisan::call('laravents:cache'))->toBe(0)
        ->and(Artisan::output())->toContain('Laravents registry cached successfully.')
        ->and(cachePath())->toBeFile()
        ->and(file_get_contents(cachePath()))->toStartWith("<?php\n\nreturn unserialize(base64_decode('");

    config(['laravents.paths' => emptyPaths()]);

    $registry = freshRegistry();

    expect($registry->commandHandler('band.give_nickname')?->handler())->toBe('Modules\Band\Handlers\BandHandlers@give')
        ->and(LaraventsServiceProvider::scan(config())->all())->toBe([]);
});

test('laravents:clear removes the file and the next resolve scans', function (): void {
    Artisan::call('laravents:cache');
    config(['laravents.paths' => emptyPaths()]);

    expect(Artisan::call('laravents:clear'))->toBe(0)
        ->and(Artisan::output())->toContain('Laravents registry cache cleared successfully.')
        ->and(cachePath())->not->toBeFile()
        ->and(freshRegistry()->all())->toBe([]);
});

test('laravents:clear with no cache file succeeds', function (): void {
    expect(Artisan::call('laravents:clear'))->toBe(0)
        ->and(cachePath())->not->toBeFile();
});

test('optimize and optimize:clear run laravents:cache and laravents:clear', function (): void {
    // Only the Laravents task: the framework's own would cache config and routes into the repository.
    expect(Artisan::call('optimize', ['--except' => 'config,events,routes,views']))->toBe(0);

    expect(Artisan::output())->toMatch('/laravents \.*\s*\S*\s*DONE/')
        ->and(cachePath())->toBeFile();

    expect(Artisan::call('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views']))->toBe(0);

    expect(Artisan::output())->toMatch('/laravents \.*\s*\S*\s*DONE/')
        ->and(cachePath())->not->toBeFile();
});

test('a discovery error fails laravents:cache with exit 1 and writes nothing', function (): void {
    config(['laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Cache/Duplicate']]);

    expect(Artisan::call('laravents:cache'))->toBe(1)
        ->and(Artisan::output())->toContain('Command routing key [cache.ping] is handled by both')
        ->and(cachePath())->not->toBeFile();
});

test('a failing laravents:cache still clears the previous cache first, as route:cache does', function (): void {
    Artisan::call('laravents:cache');
    expect(cachePath())->toBeFile();

    config(['laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Cache/Duplicate']]);

    expect(Artisan::call('laravents:cache'))->toBe(1)
        ->and(cachePath())->not->toBeFile();
});

test('the cached registry drives GiveNickname::send() end to end, interceptors included', function (): void {
    Artisan::call('laravents:cache');
    config(['laravents.paths' => emptyPaths()]);
    $registry = freshRegistry();

    $handler = $registry->commandHandler('band.give_nickname') ?? throw new RuntimeException('band.give_nickname is not in the cached registry.');
    expect(array_map(static fn (AppliedInterceptor $applied): string => $applied->interceptor->class, $registry->interceptorsFor($handler)))
        ->toBe([AuthorizationInterceptor::class, TransactionInterceptor::class])
        ->and($registry->eventHandlersFor(NicknameGiven::class))->toHaveCount(1)
        ->and($registry->routingKeys(HandlerKind::Query))->toBe(['band.get_nickname']);

    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    expect(Nickname::query()->whereKey($id)->value('given_by'))->toBe($user->id)
        ->and(EventPublication::query()->where('listener', 'Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke')->count())->toBe(1);

    // #[Authorize] from the cache: nobody nicknames themselves.
    expect(fn (): mixed => GiveNickname::send(memberId: $user->id, nickname: 'The Quiet One'))->toThrow(AuthorizationException::class);
});

test('laravents:list and laravents:show say when the registry came from the cache', function (): void {
    Artisan::call('laravents:list');
    expect(Artisan::output())->not->toContain('Registry loaded from the cache');

    Artisan::call('laravents:cache');

    Artisan::call('laravents:list');
    expect(Artisan::output())->toContain('Registry loaded from the cache [bootstrap/cache/laravents.php]. Run laravents:clear to scan again.');

    Artisan::call('laravents:show', ['module' => 'Band']);
    expect(Artisan::output())->toContain('Registry loaded from the cache [bootstrap/cache/laravents.php].');

    Artisan::call('laravents:show', ['module' => 'Band', '--json' => true]);
    expect(json_decode(Artisan::output(), true))->toBeArray();
});

test('LARAVENTS_CACHE_PATH overrides the path as APP_ROUTES_CACHE does', function (): void {
    expect(RegistryCache::for(app())->path)->toBe(app()->bootstrapPath('cache/laravents.php'));

    putenv('LARAVENTS_CACHE_PATH=storage/framework/laravents.php');
    $relative = RegistryCache::for(app())->path;
    putenv('LARAVENTS_CACHE_PATH=/tmp/laravents-registry.php');
    $absolute = RegistryCache::for(app())->path;
    putenv('LARAVENTS_CACHE_PATH');

    expect($relative)->toBe(base_path('storage/framework/laravents.php'))
        ->and($absolute)->toBe('/tmp/laravents-registry.php');
});
