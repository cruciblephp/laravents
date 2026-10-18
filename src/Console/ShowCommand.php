<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravents\Discovery\ModuleCanvas;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;

/**
 * Spring Modulith's module canvas, in the shape of Laravel's model:show.
 *
 * @phpstan-import-type Canvas from ModuleCanvas
 * @phpstan-import-type Handled from ModuleCanvas
 */
final class ShowCommand extends Command
{
    protected $signature = 'laravents:show {module : The module name, e.g. Band} {--json : Output the module as JSON}';

    protected $description = 'Show a module: the commands and queries it handles, the events it publishes and consumes, its dependencies';

    public function handle(Registry $registry, Repository $config, RegistryCache $cache): int
    {
        $canvas = ModuleCanvas::fromConfig($registry, $config);

        // The input's own value, typed the same whether or not static analysis can boot the command.
        $module = $this->input->getArgument('module');
        $module = is_string($module) ? $module : '';
        $modules = $canvas->modules();

        if (! in_array($module, $modules, true)) {
            $this->components->error(sprintf(
                'Module [%s] not found. Known modules: %s.',
                $module,
                $modules === [] ? 'none' : implode(', ', $modules),
            ));

            return self::FAILURE;
        }

        $data = $canvas->for($module);

        if ($cache->exists() && ! $this->option('json')) {
            $this->components->info($cache->note($this->laravel->basePath()));
        }

        $this->option('json') ? $this->displayJson($data) : $this->displayCli($data);

        return self::SUCCESS;
    }

    /** @param Canvas $data */
    private function displayJson(array $data): void
    {
        $this->output->writeln((new Collection($data))->toJson());
    }

    /** @param Canvas $data */
    private function displayCli(array $data): void
    {
        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>'.$data['module'].'</>');
        $this->components->twoColumnDetail('Namespace', $data['namespace']);

        $this->handled('Commands', $data['commands']);
        $this->handled('Queries', $data['queries']);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Events published</>', 'listener <fg=gray>/</> module <fg=gray>/</> queue');

        foreach ($data['events_published'] as $event) {
            if ($event['listeners'] === []) {
                $this->components->twoColumnDetail($event['event'], '-');
            }

            foreach ($event['listeners'] as $listener) {
                $this->components->twoColumnDetail($event['event'], $this->slashed([
                    $listener['handler'],
                    $listener['module'] ?? '-',
                    $listener['queue'] ?? $listener['mode'],
                ]));
            }

            foreach ($event['from'] as $publication) {
                $this->components->twoColumnDetail($event['event'], sprintf('<fg=gray>from</> %s <fg=gray>(%s)</>', class_basename($publication['model']), $publication['hook']));
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Events consumed</>', 'handler <fg=gray>/</> module <fg=gray>/</> queue');

        foreach ($data['events_consumed'] as $event) {
            $this->components->twoColumnDetail($event['event'], $this->slashed([
                $event['handler'],
                $event['module'],
                $event['queue'] ?? $event['mode'],
            ]));
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Depends on</>', 'classes');

        foreach ($data['depends_on'] as $dependency) {
            // A reference outside the module's Api is a direction violation for laravents:verify.
            $this->components->twoColumnDetail(
                $dependency['module'],
                $dependency['classes'].' '.Str::plural('class', $dependency['classes']).($dependency['outside_api'] === 0
                    ? ''
                    : ' <fg=gray>/</> <fg=red>'.$dependency['outside_api'].' outside Api</>'),
            );
        }

        $this->newLine();
    }

    /** @param list<Handled> $handled */
    private function handled(string $title, array $handled): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>'.$title.'</>',
            'handler <fg=gray>/</> message <fg=gray>/</> http',
        );

        foreach ($handled as $row) {
            $this->components->twoColumnDetail(
                trim(($row['routing_key'] ?? '-').' <fg=gray>'.implode(', ', $row['interceptors']).'</>'),
                $this->slashed([$row['handler'], $row['message'] ?? '-', $row['http'] ?? '-']),
            );
        }
    }

    /** @param list<string> $parts */
    private function slashed(array $parts): string
    {
        return implode(' <fg=gray>/</> ', $parts);
    }
}
