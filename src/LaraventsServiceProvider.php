<?php

declare(strict_types=1);

namespace Laravents;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\Context\Repository as Context;
use Illuminate\Support\Facades\Context as ContextFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravents\Bus\CommandBus;
use Laravents\Bus\ContainerPayloadConverter;
use Laravents\Bus\Engine;
use Laravents\Bus\EventBus;
use Laravents\Bus\HandleMessage;
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
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Discovery\RegistryCache;
use Laravents\Discovery\RoutingKey;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Outbox\PublisherConnection;

final class LaraventsServiceProvider extends ServiceProvider
{
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
        $this->app->bind(PayloadConverter::class, ContainerPayloadConverter::class);

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
     * laravents.modules.disabled. laravents:cache calls it too, so what is cached is exactly what a scan at boot
     * would give.
     *
     * @throws DiscoveryException
     */
    public static function scan(Repository $config): Registry
    {
        $paths = $config->get('laravents.paths', []);
        $namespace = $config->get('laravents.modules.namespace', 'Modules');
        $namespace = is_string($namespace) ? $namespace : 'Modules';
        $disabled = $config->get('laravents.modules.disabled', []);
        $disabled = is_array($disabled) ? $disabled : [];

        $classes = array_filter(
            ClassDiscovery::in(is_array($paths) ? array_values(array_filter($paths, is_string(...))) : []),
            static fn (string $class): bool => ! in_array(RoutingKey::module($class, $namespace), $disabled, true),
        );

        return (new RegistryBuilder($namespace))->build($classes);
    }

    /**
     * The cached registry when laravents:cache wrote one, else a scan.
     *
     * @throws DiscoveryException
     */
    private static function registry(Application $app): Registry
    {
        $cache = $app->make(RegistryCache::class);

        return $cache->exists() ? $cache->load() : self::scan($app->make(Repository::class));
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
