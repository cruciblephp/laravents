<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Illuminate\Support\Str;

/**
 * The --module option of the generators that write into an existing module.
 */
trait RequiresModule
{
    protected function moduleInput(): ?string
    {
        $module = $this->option('module');

        return is_string($module) && $module !== '' ? Str::studly($module) : null;
    }

    /**
     * The module the class goes into, which must already exist; null (with the error printed) otherwise.
     */
    protected function existingModule(): ?string
    {
        $module = $this->moduleInput();

        if ($module === null) {
            $this->components->error('The --module option is required, e.g. --module=Band.');

            return null;
        }

        if (! $this->files->isDirectory($this->modulesPath($module))) {
            $this->components->error(sprintf('Module [%s] does not exist. Create it with: php artisan laravents:module %s', $module, $module));

            return null;
        }

        return $module;
    }
}
