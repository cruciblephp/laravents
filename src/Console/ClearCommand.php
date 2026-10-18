<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Laravents\Discovery\RegistryCache;

/**
 * route:clear for the registry: the next boot scans again.
 */
final class ClearCommand extends Command
{
    protected $signature = 'laravents:clear';

    protected $description = 'Remove the Laravents registry cache file';

    public function handle(RegistryCache $cache): int
    {
        $cache->clear();

        $this->components->info('Laravents registry cache cleared successfully.');

        return self::SUCCESS;
    }
}
