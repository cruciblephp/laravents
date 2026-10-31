<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;
use Laravents\Exceptions\DiscoveryException;

/**
 * The Laravents section of `php artisan about`, added with AboutCommand::add() as Horizon and Telescope add theirs.
 * Everything comes from the container's registry (the cache when there is one) and the config. A registry that
 * cannot be built is one error row, as laravents:verify reports it, and the config rows are still shown.
 */
final class About
{
    /** @return array<string, string> */
    public static function rows(Application $app): array
    {
        $config = $app->make(Repository::class);

        try {
            $rows = self::registryRows($app->make(Registry::class), $app->make(RegistryCache::class)->exists());
        } catch (DiscoveryException $e) {
            $rows = ['Registry' => '<fg=red;options=bold>ERROR</> '.$e->getMessage()];
        }

        return [...$rows, ...self::configRows($config)];
    }

    /** @return array<string, string> */
    private static function registryRows(Registry $registry, bool $cacheFile): array
    {
        $modules = array_values(array_unique(array_filter(
            array_map(static fn (HandlerDefinition $handler): ?string => $handler->module, $registry->all()),
            is_string(...),
        )));
        sort($modules);

        $count = static fn (HandlerKind $kind): int => count(array_filter(
            $registry->all(),
            static fn (HandlerDefinition $handler): bool => $handler->kind === $kind,
        ));

        return [
            'Registry' => match (true) {
                $registry->fromCache() => 'Cached',
                $cacheFile => 'Scanned (the cache file is stale: run laravents:cache)',
                default => 'Scanned',
            },
            'Modules' => $modules === [] ? '0' : sprintf('%d (%s)', count($modules), implode(', ', $modules)),
            'Handlers' => sprintf(
                '%d %s / %d %s / %d %s',
                $commands = $count(HandlerKind::Command), Str::plural('command', $commands),
                $queries = $count(HandlerKind::Query), Str::plural('query', $queries),
                $events = $count(HandlerKind::Event), Str::plural('event', $events),
            ),
            'Model publications' => (string) count($registry->modelPublications()),
        ];
    }

    /** @return array<string, string> */
    private static function configRows(Repository $config): array
    {
        $prefix = $config->get('laravents.http.prefix', 'laravents');
        $connections = $config->get('laravents.outbox.connections', [null]);
        $disabled = $config->get('laravents.modules.disabled', []);
        $disabled = is_array($disabled) ? array_values(array_filter($disabled, is_string(...))) : [];

        return [
            // Only when something is off: a disabled module is otherwise absent from every other row.
            ...($disabled === [] ? [] : ['Disabled modules' => implode(', ', $disabled)]),
            'HTTP gateway' => (bool) $config->get('laravents.http.enabled', true)
                ? 'Enabled, /'.trim(is_string($prefix) ? $prefix : 'laravents', '/')
                : 'Disabled',
            'Outbox connections' => implode(', ', array_map(
                static fn (mixed $connection): string => is_string($connection) ? $connection : 'default',
                is_array($connections) ? $connections : [null],
            )),
        ];
    }
}
