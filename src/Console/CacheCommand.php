<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;
use Laravents\Exceptions\DiscoveryException;
use Laravents\LaraventsServiceProvider;
use Throwable;

/**
 * route:cache for the registry: clears, scans, and writes the result to one file the next boot loads instead of
 * scanning. A discovery error fails here, at cache time, and nothing is written. A handler added afterwards is
 * invisible until the next laravents:cache, as a route added after route:cache is.
 *
 * Before writing, the registry is read back from its plain data and held against the scan, handlers, interceptors
 * with their pointcut attributes, and module manifests included. A registry the cache would not give back as it was
 * scanned is not written: the next boot scans instead of loading something else.
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

        if (! $this->same($registry, Registry::fromArray($registry->toArray(), LaraventsServiceProvider::manifests($this->laravel)))) {
            $this->components->error('The registry read back from the cache differs from the scan, so it is not cached. A module manifest class must keep its constructor parameters as public properties.');

            return self::FAILURE;
        }

        $cache->write($registry, RegistryCache::fingerprint($config, $this->laravel->basePath()));

        $this->components->info('Laravents registry cached successfully.');

        return self::SUCCESS;
    }

    /**
     * Whether the rebuilt registry answers as the scanned one: every handler, its interceptors and every module. A
     * rebuild that throws (a manifest the converter can no longer build from what was kept) answers no.
     */
    private function same(Registry $scanned, Registry $rebuilt): bool
    {
        try {
            if ($scanned->all() != $rebuilt->all() || $scanned->modules() != $rebuilt->modules() || $scanned->modelPublications() != $rebuilt->modelPublications()) {
                return false;
            }

            foreach ($scanned->all() as $handler) {
                if ($scanned->interceptorsFor($handler) != $rebuilt->interceptorsFor($handler)) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
