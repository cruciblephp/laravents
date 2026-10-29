<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * What the example application asks a module's module.json to say: the schema laravents.modules.manifest names. Its
 * fields are the application's own, as a message's are: Laravents defines none.
 */
final readonly class ModuleManifest
{
    public function __construct(
        public string $version,
        public string $description = '',
    ) {}
}
