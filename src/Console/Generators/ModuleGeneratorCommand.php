<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

/**
 * Laravel's GeneratorCommand, rooted in the modules directory instead of app/. Stubs resolve as Laravel's own
 * generators do: a published copy in stubs/laravents/ wins over the package's.
 */
abstract class ModuleGeneratorCommand extends GeneratorCommand
{
    /** The namespace modules live under, with a trailing backslash, as GeneratorCommand expects. */
    protected function rootNamespace(): string
    {
        $namespace = config('laravents.modules.namespace', 'Modules');

        return trim(is_string($namespace) ? $namespace : 'Modules', '\\').'\\';
    }

    /** The directory the root namespace maps to. */
    protected function modulesPath(string $path = ''): string
    {
        $base = config('laravents.modules.path');
        $base = is_string($base) && $base !== '' ? $base : $this->laravel->basePath('modules');

        return rtrim($base, '/\\').($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * @param  string  $name
     */
    protected function getPath($name): string
    {
        $relative = Str::replaceFirst($this->rootNamespace(), '', $name);

        return $this->modulesPath(str_replace('\\', '/', $relative).'.php');
    }

    protected function resolveStubPath(string $stub): string
    {
        $published = $this->laravel->basePath('stubs/laravents/'.$stub);

        return file_exists($published) ? $published : dirname(__DIR__, 3).'/stubs/'.$stub;
    }

    /**
     * Renders a stub for a class: Laravel's namespace and class placeholders, then the given ones, imports sorted.
     *
     * @param  array<string, string>  $replace
     */
    protected function render(string $stub, string $class, array $replace = []): string
    {
        $content = $this->files->get($this->resolveStubPath($stub));
        $content = $this->replaceNamespace($content, $class)->replaceClass($content, $class);

        $pairs = [];

        foreach ($replace as $placeholder => $value) {
            $pairs['{{ '.$placeholder.' }}'] = $value;
            $pairs['{{'.$placeholder.'}}'] = $value;
        }

        return $this->sortImports(strtr($content, $pairs));
    }

    /** Writes a rendered file, creating its directory. */
    protected function write(string $path, string $content): void
    {
        $this->makeDirectory($path);
        $this->files->put($path, $content);
    }

    protected function force(): bool
    {
        return $this->option('force') === true;
    }
}
