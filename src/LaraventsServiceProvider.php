<?php

declare(strict_types=1);

namespace Laravents;

use Closure;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\Context\Repository as Context;
use Illuminate\Support\Facades\Context as ContextFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravents\Bus\CommandBus;
use Laravents\Bus\ContainerPayloadConverter;
use Laravents\Bus\ContextMetadata;
use Laravents\Bus\Engine;
use Laravents\Bus\EventBus;
use Laravents\Bus\HandleMessage;
use Laravents\Bus\MetadataPipe;
use Laravents\Bus\ModelEvents;
use Laravents\Bus\NativeEvents;
use Laravents\Bus\PayloadConverter;
use Laravents\Bus\QueryBus;
use Laravents\Console\About;
use Laravents\Console\CacheCommand;
use Laravents\Console\ClearCommand;
use Laravents\Console\DocsCommand;
use Laravents\Console\Generators\CommandMakeCommand;
use Laravents\Console\Generators\EventMakeCommand;
use Laravents\Console\Generators\InterceptorMakeCommand;
use Laravents\Console\Generators\ModuleMakeCommand;
use Laravents\Console\Generators\QueryMakeCommand;
use Laravents\Console\ListCommand;
use Laravents\Console\RepublishCommand;
use Laravents\Console\ShowCommand;
use Laravents\Console\VerifyCommand;
use Laravents\Contracts\CommandBus as CommandBusContract;
use Laravents\Contracts\EventBus as EventBusContract;
use Laravents\Contracts\QueryBus as QueryBusContract;
use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\ManifestReader;
use Laravents\Discovery\Module;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Discovery\RegistryCache;
use Laravents\Discovery\RoutingKey;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Outbox\PublisherConnection;

final class LaraventsServiceProvider extends ServiceProvider
{
    /** Bound once the stale-cache warning was logged for this application. */
    private const string STALE_WARNED = 'laravents.cache.stale-warned';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravents.php', 'laravents');

        $this->app->singleton(RegistryCache::class, static fn (Application $app): RegistryCache => RegistryCache::for($app));

        // The cached registry when laravents:cache wrote one, else a scan, as the router does with its cache.
        $this->app->singleton(Registry::class, static fn (Application $app): Registry => self::registry($app));

        $this->app->singleton(Engine::class);
        // Bound per use: it reads whatever db.transactions is now (a test's RefreshDatabase swaps the instance).
        $this->app->bind(PublisherConnection::class, static fn (Application $app): PublisherConnection => new PublisherConnection($app->make('db.transactions')));
        // The same shape for the deferred handlers' after-commit registration; null where no database is bound.
        $this->app->bind(HandleMessage::class, static fn (Application $app): HandleMessage => new HandleMessage(
            $app,
            $app->make(Registry::class),
            $app->make(PayloadConverter::class),
            $app->make(BusDispatcher::class),
            $app->bound('db.transactions') ? $app->make('db.transactions') : null,
        ));
        // Scoped, not bound: stateless over the container, one per request or job, flushed with Context.
        $this->app->scoped(PayloadConverter::class, ContainerPayloadConverter::class);
        $this->app->scoped(MetadataPipe::class);
        $this->app->scoped(ContextMetadata::class);

        // Everything that dispatches depends on the contracts, so a fake bound in their place reaches it all.
        $this->app->bind(CommandBusContract::class, CommandBus::class);
        $this->app->bind(QueryBusContract::class, QueryBus::class);
        $this->app->bind(EventBusContract::class, EventBus::class);
    }

    public function boot(): void
    {
        // The actor is a claim only a guard can back. A plain job has no guard, so it must not inherit one
        // through Context; ExecuteHandlerJob carries its actor itself. The ids keep travelling.
        ContextFacade::dehydrating(static function (Context $context): void {
            $context->forget(['actor_id', 'actor_guard']);
        });

        $this->subscribeEvents();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->optimizes(optimize: 'laravents:cache', clear: 'laravents:clear', key: 'laravents');
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            AboutCommand::add('Laravents', static fn (Application $app): array => About::rows($app));

            $this->publishes([
                __DIR__.'/../config/laravents.php' => config_path('laravents.php'),
            ], 'laravents-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'laravents-migrations');

            // A published stub in stubs/laravents/ wins over the package's, as Laravel's generators do.
            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/laravents'),
            ], 'laravents-stubs');

            $this->commands([
                CacheCommand::class,
                ClearCommand::class,
                ListCommand::class,
                ShowCommand::class,
                DocsCommand::class,
                VerifyCommand::class,
                RepublishCommand::class,
                ModuleMakeCommand::class,
                CommandMakeCommand::class,
                QueryMakeCommand::class,
                EventMakeCommand::class,
                InterceptorMakeCommand::class,
            ]);
        }
    }

    /**
     * Builds the registry from the classes under laravents.paths, less those of the modules in
     * laravents.modules.disabled, with every module listed, the disabled ones too, each with its manifest
     * (Module::discover(), ManifestReader). laravents:cache calls it too, so what is cached is exactly what a scan at
     * boot would give. The container hydrates the manifests; the current one when none is given.
     *
     * @throws DiscoveryException
     */
    public static function scan(Repository $config, ?Container $container = null): Registry
    {
        $paths = $config->get('laravents.paths', []);
        $namespace = $config->get('laravents.modules.namespace', 'Modules');
        $namespace = is_string($namespace) ? $namespace : 'Modules';
        $disabled = $config->get('laravents.modules.disabled', []);
        $disabled = is_array($disabled) ? array_values(array_filter($disabled, is_string(...))) : [];
        $all = ClassDiscovery::in(is_array($paths) ? array_values(array_filter($paths, is_string(...))) : []);

        $classes = array_filter(
            $all,
            static fn (string $class): bool => ! in_array(RoutingKey::module($class, $namespace), $disabled, true),
        );

        $manifests = ManifestReader::fromConfig($config, $container ?? ContainerInstance::getInstance());

        return (new RegistryBuilder($namespace))->build($classes, Module::discover($all, $namespace, $disabled, $manifests));
    }

    /**
     * The cached registry when laravents:cache wrote one this version can trust, else a scan. Under
     * `laravents.cache.stale` = `scan` (the default) a cache written for another configuration is not trusted: a
     * module disabled since is out at once, and the log says the cache is stale, once per process.
     *
     * @throws DiscoveryException
     */
    private static function registry(Application $app): Registry
    {
        $cache = $app->make(RegistryCache::class);
        $config = $app->make(Repository::class);

        if (! $cache->exists()) {
            return self::scan($config, $app);
        }

        $fingerprint = $config->get('laravents.cache.stale', 'scan') === 'cache' ? null : RegistryCache::fingerprint($config, $app->basePath());

        return $cache->load($fingerprint, self::manifests($app)) ?? self::scanStale($app, $config, $cache);
    }

    /**
     * Rebuilds a cached module manifest the way discovery built it: through the payload converter, from the fields
     * the cache kept.
     *
     * @return Closure(class-string, array<string, mixed>): object
     */
    public static function manifests(Container $container): Closure
    {
        return static function (string $class, array $fields) use ($container): object {
            /** @var class-string $class */
            /** @var array<string, mixed> $fields */
            return $container->make(PayloadConverter::class)->convert($class, $fields);
        };
    }

    /**
     * A cache file is there but cannot be used: scan, and say so once per application, so a stale cache costs a
     * warning and a scan instead of serving yesterday's handlers. The mark lives in the container, not in a static:
     * one application is one process in production (one worker under Octane), and a test's fresh application warns
     * afresh.
     *
     * @throws DiscoveryException
     */
    private static function scanStale(Application $app, Repository $config, RegistryCache $cache): Registry
    {
        if (! $app->bound(self::STALE_WARNED) && $app->bound('log')) {
            $app->instance(self::STALE_WARNED, true);
            $app->make('log')->warning(sprintf(
                'The Laravents registry cache [%s] was written for another configuration, version or format; scanning instead. Run php artisan laravents:cache.',
                $cache->path,
            ));
        }

        return self::scan($config, $app);
    }

    /**
     * Event::listen() for every framework or application event an #[EventHandler] takes, and for every model event a
     * #[Publishes] names. This needs the registry at boot, which the cache (laravents:cache) makes a file read. In the
     * console a broken registry is left for the command to meet, so laravents:verify and laravents:cache can still
     * report it instead of failing to boot.
     */
    private function subscribeEvents(): void
    {
        try {
            $registry = self::registry($this->app);
        } catch (DiscoveryException $e) {
            if ($this->app->runningInConsole()) {
                return;
            }

            throw $e;
        }

        // The singleton's instance from here on, so boot does not build it twice.
        $this->app->instance(Registry::class, $registry);
        $this->app->make(NativeEvents::class)->subscribe($registry, $this->app->make(Dispatcher::class));
        $this->app->make(ModelEvents::class)->subscribe($registry, $this->app->make(Dispatcher::class));
    }

    /**
     * The HTTP gateway, as Laravel packages register their routes: a group with the configured prefix and
     * middleware around the package's route file, which loadRoutesFrom() skips when routes are cached.
     */
    private function registerRoutes(): void
    {
        $config = $this->app->make(Repository::class);

        if (! (bool) $config->get('laravents.http.enabled', true)) {
            return;
        }

        $prefix = $config->get('laravents.http.prefix', 'laravents');
        $middleware = $config->get('laravents.http.middleware', []);

        Route::group([
            'prefix' => is_string($prefix) ? $prefix : 'laravents',
            'middleware' => is_array($middleware) ? $middleware : [],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/http.php');
        });
    }
}
