<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Creates a module: its Api, Handlers and migrations directories, its service provider, registered in
 * bootstrap/providers.php as make:provider does, and a first feature test, PHPUnit or Pest as make:test decides.
 */
#[AsCommand(name: 'laravents:module')]
final class ModuleMakeCommand extends ModuleGeneratorCommand
{
    protected $name = 'laravents:module';

    protected $description = 'Create a module with its service provider and a first test';

    protected $type = 'Module';

    protected function getStub(): string
    {
        return $this->resolveStubPath('provider.stub');
    }

    public function handle(): ?bool
    {
        if ($this->isReservedName($this->getNameInput())) {
            $this->components->error('The name "'.$this->getNameInput().'" is reserved by PHP.');

            return false;
        }

        $module = Str::studly(str_replace(['/', '\\'], '', $this->getNameInput()));
        $provider = $this->rootNamespace().$module.'\\'.$module.'ServiceProvider';
        $providerPath = $this->getPath($provider);
        $testPath = $this->laravel->basePath('tests/Feature/Modules/'.$module.'Test.php');

        if (! $this->force()) {
            foreach ([$providerPath, $testPath] as $path) {
                if ($this->files->exists($path)) {
                    $this->components->error(sprintf('Module [%s] already exists: [%s].', $module, $path));

                    return false;
                }
            }
        }

        foreach (['Api', 'Handlers', 'database/migrations'] as $directory) {
            $keep = $this->modulesPath($module.'/'.$directory.'/.gitkeep');
            $this->makeDirectory($keep);

            if (! $this->files->exists($keep)) {
                $this->files->put($keep, '');
            }
        }

        $this->write($providerPath, $this->render('provider.stub', $provider));
        $this->write($testPath, $this->render($this->usingPest() ? 'module-test.pest.stub' : 'module-test.stub', $module, ['module' => $module]));

        ServiceProvider::addProviderToBootstrapFile($provider, $this->laravel->getBootstrapProvidersPath());

        $this->components->info(sprintf('Module [%s] created successfully.', $this->modulesPath($module)));
        $this->components->info(sprintf('Provider [%s] registered in [%s].', $provider, $this->laravel->getBootstrapProvidersPath()));
        $this->components->info(sprintf('Test [%s] created successfully.', $testPath));

        return null;
    }

    /** @return list<array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the provider and the test if they already exist'],
            ['pest', null, InputOption::VALUE_NONE, 'Create a Pest test'],
            ['phpunit', null, InputOption::VALUE_NONE, 'Create a PHPUnit test'],
        ];
    }

    /** Whether the application uses Pest, decided as make:test decides it. */
    private function usingPest(): bool
    {
        if ($this->option('phpunit') === true) {
            return false;
        }

        return $this->option('pest') === true
            || (function_exists('\Pest\version') && file_exists($this->laravel->basePath('tests').'/Pest.php'));
    }
}
