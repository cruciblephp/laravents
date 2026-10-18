<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Laravents\Discovery\RegistryCache;
use Laravents\Exceptions\DiscoveryException;
use Laravents\LaraventsServiceProvider;

/**
 * route:cache for the registry: clears, scans, and writes the result to one file the next boot loads instead of
 * scanning. A discovery error fails here, at cache time, and nothing is written. A handler added afterwards is
 * invisible until the next laravents:cache, as a route added after route:cache is.
 */
final class CacheCommand extends Command
{
    protected $signature = 'laravents:cache';

    protected $description = 'Create a cache file of the Laravents registry for faster boot';

    public function handle(Repository $config, RegistryCache $cache): int
    {
        $this->callSilent('laravents:clear');

        try {
            $registry = LaraventsServiceProvider::scan($config);
        } catch (DiscoveryException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $cache->write($registry);

        $this->components->info('Laravents registry cached successfully.');

        return self::SUCCESS;
    }
}
