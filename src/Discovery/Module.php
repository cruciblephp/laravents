<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Laravents\Exceptions\DiscoveryException;

/**
 * One module as Laravents knows it: its name, its namespace, whether laravents.modules.disabled turns it off, and its
 * manifest, the module's module.json hydrated into the application's manifest class (ManifestReader). Laravents
 * defines no field of a manifest: the class is the application's schema, and Laravents only finds, checks and keeps it.
 */
final readonly class Module
{
    public function __construct(
        public string $name,
        public string $namespace,
        public bool $disabled,
        public ?object $manifest,
    ) {}

    /**
     * Every module with a class among the discovered ones, by name and sorted, the disabled ones included, as
     * laravents:verify sees them, each with its manifest when a reader is given.
     *
     * @param  iterable<class-string>  $classes  The discovered classes, before laravents.modules.disabled is applied
     * @param  list<string>  $disabled  laravents.modules.disabled
     * @return array<string, Module>
     *
     * @throws DiscoveryException
     */
    public static function discover(iterable $classes, string $rootNamespace, array $disabled = [], ?ManifestReader $manifests = null): array
    {
        $root = trim($rootNamespace, '\\');
        $names = [];

        foreach ($classes as $class) {
            $name = RoutingKey::module($class, $root);

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        ksort($names);

        $modules = [];

        foreach (array_keys($names) as $name) {
            $modules[$name] = new self(
                $name,
                $root.'\\'.$name,
                in_array($name, $disabled, true),
                $manifests?->read($name),
            );
        }

        return $modules;
    }
}
