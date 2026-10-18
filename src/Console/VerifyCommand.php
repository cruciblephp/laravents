<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Verify\Dependency;
use Laravents\Verify\DependencyScanner;
use Laravents\Verify\Verifier;

/**
 * Spring Modulith's verify() as an Artisan command, for CI without a test runner: dependency direction, Api purity,
 * cycles between modules, and the registry itself.
 *
 * @phpstan-type Violation array{rule: string, severity: 'error'|'warning', message: string, class: string|null, uses: string|null, file: string|null, line: int|null}
 */
final class VerifyCommand extends Command
{
    private const array RULES = [
        'registry' => 'Registry',
        'direction' => 'Direction',
        'api_purity' => 'Api purity',
        'cycle' => 'Cycles',
    ];

    protected $signature = 'laravents:verify
        {--json : Output the result as JSON}
        {--allow-cycles : Report cycles between modules as warnings instead of failures}';

    protected $description = 'Verify the module boundaries: dependency direction, Api purity and cycles';

    public function handle(Repository $config): int
    {
        $paths = $config->get('laravents.paths', []);
        $namespace = $config->get('laravents.modules.namespace', 'Modules');
        $namespace = is_string($namespace) ? $namespace : 'Modules';
        $paths = is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
        $classes = ClassDiscovery::structures($paths);

        $verifier = new Verifier($namespace);
        $dependencies = (new DependencyScanner($namespace))->scan(array_values(array_unique([...$classes, ...ClassDiscovery::structures([app_path()])])));
        $modules = $verifier->modules($classes);
        $graph = $verifier->graph($modules, $dependencies);
        $cycleSeverity = $this->option('allow-cycles') ? 'warning' : 'error';

        $violations = [
            ...$this->registry($namespace, $paths),
            ...array_map(fn (Dependency $dependency): array => $this->dependencyViolation('direction', $dependency), $verifier->direction($dependencies)),
            ...array_map(fn (Dependency $dependency): array => $this->dependencyViolation('api_purity', $dependency), $verifier->apiPurity($dependencies)),
            ...array_map(static fn (array $cycle): array => [
                'rule' => 'cycle',
                'severity' => $cycleSeverity,
                'message' => implode(' -> ', $cycle),
                'class' => null,
                'uses' => null,
                'file' => null,
                'line' => null,
            ], $verifier->cycles($graph)),
        ];

        $failed = array_filter($violations, static fn (array $violation): bool => $violation['severity'] === 'error') !== [];

        $this->option('json')
            ? $this->displayJson($modules, $graph, $violations, ! $failed)
            : $this->displayCli($modules, $graph, $violations, ! $failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Building the registry, as the service provider does, surfaces duplicate keys, invalid attributes and unknown
     * pointcuts.
     *
     * @param  list<string>  $paths
     * @return list<Violation>
     */
    private function registry(string $namespace, array $paths): array
    {
        try {
            (new RegistryBuilder($namespace))->build(ClassDiscovery::in($paths));
        } catch (DiscoveryException $e) {
            return [[
                'rule' => 'registry',
                'severity' => 'error',
                'message' => $e->getMessage(),
                'class' => null,
                'uses' => null,
                'file' => null,
                'line' => null,
            ]];
        }

        return [];
    }

    /** @return Violation */
    private function dependencyViolation(string $rule, Dependency $dependency): array
    {
        $file = Str::after($dependency->file, $this->laravel->basePath().DIRECTORY_SEPARATOR);

        return [
            'rule' => $rule,
            'severity' => 'error',
            'message' => sprintf('%s uses %s (%s:%d)', $dependency->class, $dependency->uses, $file, $dependency->line),
            'class' => $dependency->class,
            'uses' => $dependency->uses,
            'file' => $file,
            'line' => $dependency->line,
        ];
    }

    /**
     * @param  list<string>  $modules
     * @param  array<string, list<string>>  $graph
     * @param  list<Violation>  $violations
     */
    private function displayJson(array $modules, array $graph, array $violations, bool $passed): void
    {
        $this->output->writeln((new Collection([
            'modules' => $modules,
            'graph' => $graph,
            'violations' => $violations,
            'passed' => $passed,
        ]))->toJson(JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<string>  $modules
     * @param  array<string, list<string>>  $graph
     * @param  list<Violation>  $violations
     */
    private function displayCli(array $modules, array $graph, array $violations, bool $passed): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Module graph</>', count($modules).' '.Str::plural('module', count($modules)));

        $targets = array_merge(...array_values($graph));

        foreach ($graph as $module => $dependsOn) {
            if ($dependsOn === [] && ! in_array($module, $targets, true)) {
                $this->line('  '.$module);
            }

            foreach ($dependsOn as $target) {
                $this->line(sprintf('  %s <fg=gray>-></> %s', $module, $target));
            }
        }

        foreach (self::RULES as $rule => $title) {
            $found = array_values(array_filter($violations, static fn (array $violation): bool => $violation['rule'] === $rule));

            if ($found === []) {
                continue;
            }

            $warning = $found[0]['severity'] === 'warning';
            $label = $rule === 'cycle' ? Str::plural('cycle', count($found)) : Str::plural('violation', count($found));

            $this->newLine();
            $this->components->twoColumnDetail(
                sprintf('<fg=%s;options=bold>%s</>', $warning ? 'yellow' : 'red', $title),
                count($found).' '.$label.($warning ? ' (allowed)' : ''),
            );

            foreach ($found as $violation) {
                $warning ? $this->components->warn($violation['message']) : $this->components->error($violation['message']);
            }
        }

        if ($passed) {
            $allowed = count(array_filter($violations, static fn (array $violation): bool => $violation['severity'] === 'warning'));

            $this->newLine();
            $this->components->info(sprintf(
                '%d %s verified, no violations%s.',
                count($modules),
                Str::plural('module', count($modules)),
                $allowed === 0 ? '' : sprintf(' (%d %s allowed)', $allowed, Str::plural('cycle', $allowed)),
            ));
        }
    }
}
