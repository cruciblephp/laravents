<?php

declare(strict_types=1);

namespace Laravents\Verify;

use Laravents\Discovery\RoutingKey;

/**
 * Spring Modulith's verify() over the scanner's dependencies: direction, Api purity and cycles.
 */
final readonly class Verifier
{
    public function __construct(private string $rootNamespace) {}

    /**
     * Every module with a discovered class, sorted by name.
     *
     * @param  list<string>  $classes
     * @return list<string>
     */
    public function modules(array $classes): array
    {
        $modules = array_values(array_unique(array_filter(
            array_map(fn (string $class): ?string => RoutingKey::module($class, $this->rootNamespace), $classes),
            is_string(...),
        )));
        sort($modules);

        return $modules;
    }

    /**
     * Each module and the other modules it references, through their Api or not, both sorted by name.
     *
     * @param  list<string>  $modules
     * @param  list<Dependency>  $dependencies
     * @return array<string, list<string>>
     */
    public function graph(array $modules, array $dependencies): array
    {
        $graph = array_fill_keys($modules, []);

        foreach ($dependencies as $dependency) {
            [$from, $to] = $this->modulesOf($dependency);

            if ($from !== null && $to !== null && $from !== $to) {
                $graph[$from][] = $to;
            }
        }

        ksort($graph);

        return array_map(static function (array $targets): array {
            $targets = array_values(array_unique($targets));
            sort($targets);

            return $targets;
        }, $graph);
    }

    /**
     * Rule 1: a class outside a module, in another module or in no module at all, references it only under its Api.
     *
     * @param  list<Dependency>  $dependencies
     * @return list<Dependency>
     */
    public function direction(array $dependencies): array
    {
        return array_values(array_filter($dependencies, function (Dependency $dependency): bool {
            [$from, $to] = $this->modulesOf($dependency);

            return $to !== null && $from !== $to && ! $this->inApi($dependency->uses, $to);
        }));
    }

    /**
     * Rule 2: a class under a module's Api references nothing of its own module outside the Api.
     *
     * @param  list<Dependency>  $dependencies
     * @return list<Dependency>
     */
    public function apiPurity(array $dependencies): array
    {
        return array_values(array_filter($dependencies, function (Dependency $dependency): bool {
            [$from, $to] = $this->modulesOf($dependency);

            return $from !== null && $from === $to && $this->inApi($dependency->class, $from) && ! $this->inApi($dependency->uses, $to);
        }));
    }

    /**
     * Rule 3: every elementary cycle of the module graph, each once, starting and ending at its smallest module
     * (a depth-first search from each module through larger ones only; module graphs are small).
     *
     * @param  array<string, list<string>>  $graph
     * @return list<list<string>>
     */
    public function cycles(array $graph): array
    {
        $cycles = [];

        foreach (array_keys($graph) as $start) {
            $this->walk($graph, (string) $start, [(string) $start], $cycles);
        }

        return $cycles;
    }

    /**
     * @param  array<string, list<string>>  $graph
     * @param  non-empty-list<string>  $path
     * @param  list<list<string>>  $cycles
     */
    private function walk(array $graph, string $start, array $path, array &$cycles): void
    {
        foreach ($graph[$path[array_key_last($path)]] ?? [] as $next) {
            if ($next === $start) {
                $cycles[] = [...$path, $start];
            } elseif (strcmp($next, $start) > 0 && ! in_array($next, $path, true)) {
                $this->walk($graph, $start, [...$path, $next], $cycles);
            }
        }
    }

    /** @return array{0: string|null, 1: string|null} */
    private function modulesOf(Dependency $dependency): array
    {
        return [
            RoutingKey::module($dependency->class, $this->rootNamespace),
            RoutingKey::module($dependency->uses, $this->rootNamespace),
        ];
    }

    private function inApi(string $class, string $module): bool
    {
        return str_starts_with($class, trim($this->rootNamespace, '\\').'\\'.$module.'\\Api\\');
    }
}
