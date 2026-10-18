<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Spatie\StructureDiscoverer\Discover;
use Spatie\StructureDiscoverer\Enums\DiscoveredStructureType;
use Spatie\StructureDiscoverer\Enums\Sort;

/**
 * Finds the classes in the configured paths. Sort::Name is Finder's sortByName(), so files are ordered by path;
 * under PSR-4 that follows the class name. The order is stable, and it is the discovery order.
 */
final class ClassDiscovery
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public static function in(array $paths): array
    {
        $paths = array_values(array_filter($paths, is_dir(...)));

        if ($paths === []) {
            return [];
        }

        /** @var list<class-string> */
        return array_values(Discover::in(...$paths)->classes()->sortBy(Sort::Name)->get());
    }

    /**
     * The classes, interfaces, traits and enums in the configured paths, in the same order: what the dependency
     * scanner reads, since an interface or an enum can cross a module boundary as well as a class.
     *
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public static function structures(array $paths): array
    {
        $paths = array_values(array_filter($paths, is_dir(...)));

        if ($paths === []) {
            return [];
        }

        /** @var list<class-string> */
        return array_values(Discover::in(...$paths)
            ->types(DiscoveredStructureType::ClassDefinition, DiscoveredStructureType::Interface, DiscoveredStructureType::Trait, DiscoveredStructureType::Enum)
            ->sortBy(Sort::Name)
            ->get());
    }
}
