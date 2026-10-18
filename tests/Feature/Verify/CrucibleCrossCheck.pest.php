<?php

declare(strict_types=1);

use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\RoutingKey;
use Laravents\Verify\DependencyScanner;
use LucianoPereira\Crucible\Architecture\ArchitectureUniverse;
use LucianoPereira\Crucible\Architecture\ArchPredicates;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

/*
 * The scanner against Crucible's own arch extraction, over the same fixture module sets: the four rule fixtures and
 * Probe, where each Billing\Probes class reaches one Orders\Api class through exactly one construct and nothing
 * imports it, so an import cannot hide a miss. Crucible's side is what its toOnlyUse() evaluates: the universe of
 * the configured source, ArchPredicates::crossingsOf() for a module's references into other modules, and
 * referencesOf() for those within the module (what Api purity reads).
 */

/**
 * Every `Class -> Referenced` pair within the fixture set's namespace, scanner side and Crucible side.
 *
 * @return array{scanner: list<string>, crucible: list<string>}
 */
function crossCheck(string $set): array
{
    $root = 'Laravents\Tests\Fixtures\Verify\\'.$set;
    $directory = 'tests/Fixtures/Verify/'.$set;

    $scanner = [];

    foreach (new DependencyScanner($root)->scan(ClassDiscovery::structures([dirname(__DIR__, 3).'/'.$directory])) as $dependency) {
        $scanner[] = $dependency->class.' -> '.$dependency->uses;
    }

    $universe = new ArchitectureUniverse(new Source(includeDirectories: [$directory]), new WorkingDirectory(dirname(__DIR__, 3)));
    $modules = array_unique(array_filter(array_map(
        static fn (string $class): ?string => RoutingKey::module($class, $root),
        array_keys($universe->classes()),
    )));
    $crucible = [];

    foreach ($modules as $module) {
        $targets = $universe->matching($root.'\\'.$module);

        foreach ($targets as $class) {
            foreach (ArchPredicates::crossingsOf($universe, $targets, $class) as $reference) {
                $crucible[] = $class.' -> '.$reference;
            }

            foreach ($universe->referencesOf($class) as $reference) {
                if ($reference !== $class && in_array($reference, $targets, true)) {
                    $crucible[] = $class.' -> '.$reference;
                }
            }
        }
    }

    sort($scanner);
    sort($crucible);

    return ['scanner' => $scanner, 'crucible' => $crucible];
}

test('the scanner and Crucible see the same classes in every fixture set', function (string $set, int $count): void {
    $universe = new ArchitectureUniverse(new Source(includeDirectories: ['tests/Fixtures/Verify/'.$set]), new WorkingDirectory(dirname(__DIR__, 3)));
    $classes = ClassDiscovery::structures([dirname(__DIR__, 2).'/Fixtures/Verify/'.$set]);
    sort($classes);

    // Each set's own count first, so a discovery that finds nothing on both sides cannot pass.
    expect($classes)->toHaveCount($count)
        ->and(array_keys($universe->classes()))->toBe($classes);
})->with([
    'Clean' => ['Clean', 14],
    'Direction' => ['Direction', 3],
    'Purity' => ['Purity', 3],
    'Cycle' => ['Cycle', 4],
    'Probe' => ['Probe', 56],
]);

test('the scanner and Crucible agree on every reference of the rule fixtures', function (string $set): void {
    $found = crossCheck($set);

    expect($found['scanner'])->not->toBe([])
        ->and($found['scanner'])->toBe($found['crucible']);
})->with(['Clean', 'Direction', 'Purity', 'Cycle']);

test('over the probes, the scanner and Crucible agree on every reference', function (): void {
    $found = crossCheck('Probe');

    expect($found['scanner'])->toHaveCount(36)
        ->and($found['scanner'])->toBe($found['crucible']);
});
