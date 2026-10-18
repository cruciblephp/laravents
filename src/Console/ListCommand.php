<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;
use Laravents\Http\Endpoints;

final class ListCommand extends Command
{
    protected $signature = 'laravents:list {--module= : Only handlers in this module}';

    protected $description = 'List the discovered command, query and event handlers';

    public function handle(Registry $registry, Repository $config, RegistryCache $cache): int
    {
        if ($cache->exists()) {
            $this->components->info($cache->note($this->laravel->basePath()));
        }

        $endpoints = Endpoints::fromConfig($config);
        $module = $this->option('module');
        $handlers = array_filter(
            $registry->all(),
            static fn (HandlerDefinition $handler): bool => ! is_string($module) || $handler->module === $module,
        );

        if ($handlers === []) {
            $this->components->info('No handlers found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Kind', 'Key', 'Message', 'Handler', 'Mode', 'Queue', 'HTTP'],
            array_map(static fn (HandlerDefinition $handler): array => [
                $handler->kind->label(),
                $handler->routingKey ?? '-',
                $handler->messageClass ?? '-',
                $handler->handler(),
                $handler->mode->value,
                $handler->queue ?? '-',
                $endpoints->for($handler) ?? '-',
            ], $handlers),
        );

        return self::SUCCESS;
    }
}
