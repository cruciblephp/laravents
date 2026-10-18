<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Laravents\Discovery\ModuleCanvas;
use Laravents\Discovery\Registry;
use Laravents\Docs\ModuleDocs;

/**
 * The module canvases as Markdown: README.md (the module list and a Mermaid dependency graph) and one <Module>.md
 * per module, from what laravents:show and laravents:verify compute. A file whose content is unchanged is not
 * rewritten, so running it twice changes nothing. --check writes nothing: it lists each file that is stale or missing
 * and exits 1, or says the docs are current and exits 0, for CI next to laravents:verify.
 */
final class DocsCommand extends Command
{
    protected $signature = 'laravents:docs
                            {--path= : The directory to write into, under the base path (default: laravents.docs.path)}
                            {--realpath : Indicate the given --path is a pre-resolved absolute path}
                            {--check : Write nothing; exit 1 when a file is stale or missing (for CI)}';

    protected $description = 'Write one Markdown page per module and an index with the Mermaid dependency graph (--check: fail when they are out of date)';

    public function handle(Registry $registry, Repository $config, Filesystem $files): int
    {
        $canvas = ModuleCanvas::fromConfig($registry, $config);
        $canvases = array_map($canvas->for(...), $canvas->modules());
        $directory = $this->directory($config);

        $pages = ['README.md' => ModuleDocs::index($canvases)];

        foreach ($canvases as $module) {
            $pages[$module['module'].'.md'] = ModuleDocs::page($module);
        }

        return $this->option('check') ? $this->check($files, $directory, $pages) : $this->write($files, $directory, $pages, count($canvases));
    }

    /** @param  array<string, string>  $pages */
    private function write(Filesystem $files, string $directory, array $pages, int $modules): int
    {
        $files->ensureDirectoryExists($directory);

        $this->newLine();

        foreach ($pages as $name => $content) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $unchanged = $files->exists($path) && $files->get($path) === $content;

            if (! $unchanged) {
                $files->put($path, $content);
            }

            $this->components->twoColumnDetail($this->relative($path), $unchanged ? '<fg=gray>unchanged</>' : '<fg=green>written</>');
        }

        $this->newLine();
        $this->components->info(sprintf('Module docs for %d %s written to [%s].', $modules, Str::plural('module', $modules), $this->relative($directory)));

        return self::SUCCESS;
    }

    /**
     * Compares each page with the file on disk and writes nothing.
     *
     * @param  array<string, string>  $pages
     */
    private function check(Filesystem $files, string $directory, array $pages): int
    {
        $outdated = 0;

        $this->newLine();

        foreach ($pages as $name => $content) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $status = match (true) {
                ! $files->exists($path) => 'missing',
                $files->get($path) !== $content => 'stale',
                default => 'current',
            };
            $outdated += $status === 'current' ? 0 : 1;

            $this->components->twoColumnDetail($this->relative($path), $status === 'current' ? '<fg=gray>current</>' : '<fg=red;options=bold>'.$status.'</>');
        }

        $this->newLine();

        if ($outdated > 0) {
            $this->components->error(sprintf('%d %s in [%s] %s out of date. Run php artisan laravents:docs.', $outdated, Str::plural('file', $outdated), $this->relative($directory), $outdated === 1 ? 'is' : 'are'));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Module docs in [%s] are current.', $this->relative($directory)));

        return self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return Str::after($path, $this->laravel->basePath().DIRECTORY_SEPARATOR);
    }

    /** --path under the base path (as given with --realpath, as migrate takes it), else laravents.docs.path. */
    private function directory(Repository $config): string
    {
        $option = $this->option('path');

        if (is_string($option) && $option !== '') {
            return rtrim($this->option('realpath') === true ? $option : $this->laravel->basePath($option), '/\\');
        }

        $path = $config->get('laravents.docs.path');

        return rtrim(is_string($path) && $path !== '' ? $path : base_path('docs/laravents'), '/\\');
    }
}
