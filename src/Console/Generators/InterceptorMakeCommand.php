<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Attribute;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Writes an interceptor into the module's Interceptors namespace. The pointcut is an existing class or interface
 * (fully qualified), or an attribute in the module's Attributes namespace, written too when it does not exist,
 * because discovery refuses an interceptor whose pointcut is unknown. An existing pointcut file is never overwritten.
 */
#[AsCommand(name: 'laravents:interceptor')]
final class InterceptorMakeCommand extends ModuleGeneratorCommand
{
    use RequiresModule;

    private const array KINDS = ['before', 'around', 'after'];

    protected $name = 'laravents:interceptor';

    protected $description = 'Create an #[Around], #[Before] or #[After] interceptor in a module';

    protected $type = 'Interceptor';

    protected function getStub(): string
    {
        return $this->resolveStubPath('interceptor.around.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\'.$this->moduleInput().'\\Interceptors';
    }

    public function handle(): ?bool
    {
        if ($this->isReservedName($this->getNameInput())) {
            $this->components->error('The name "'.$this->getNameInput().'" is reserved by PHP.');

            return false;
        }

        $kinds = array_values(array_filter(self::KINDS, fn (string $kind): bool => $this->option($kind) === true));

        if (count($kinds) > 1) {
            $this->components->error('Pass one of --before, --around and --after.');

            return false;
        }

        $module = $this->existingModule();

        if ($module === null) {
            return false;
        }

        $interceptor = $this->qualifyClass($this->getNameInput());
        $pointcut = $this->pointcut($module);
        $loaded = class_exists($pointcut) || interface_exists($pointcut);
        // A pointcut that exists is kept as it is, even with --force: it may have grown fields since.
        $writePointcut = ! $loaded;
        $paths = [$this->getPath($interceptor) => $this->type];

        if ($writePointcut) {
            $paths[$this->getPath($pointcut)] = 'Pointcut';
        }

        if (! $this->force()) {
            foreach ($paths as $path => $type) {
                if ($this->files->exists($path)) {
                    $this->components->error(sprintf('%s [%s] already exists.', $type, $path));

                    return false;
                }
            }
        }

        if ($writePointcut) {
            $this->write($this->getPath($pointcut), $this->render('pointcut.stub', $pointcut));
        }

        // An interceptor named as its pointcut (the default) cannot import it under the same name.
        $short = class_basename($pointcut);
        $alias = $short === class_basename($interceptor) ? $short.'Pointcut' : $short;

        // The rule parameter receives the attribute instance on the handler, so only an attribute pointcut gets one.
        $rule = ! $loaded || $this->isAttribute($pointcut) ? ', '.$alias.' $rule' : '';

        $this->write($this->getPath($interceptor), $this->render('interceptor.'.($kinds[0] ?? 'around').'.stub', $interceptor, [
            'namespacedPointcut' => $alias === $short ? $pointcut : $pointcut.' as '.$alias,
            'pointcut' => $alias,
            'rule' => $rule,
        ]));

        foreach (array_keys($paths) as $path) {
            $this->components->info(sprintf('%s [%s] created successfully.', $paths[$path], $path));
        }

        return null;
    }

    /**
     * --pointcut as given when it names an existing class or interface, else an attribute in the module's
     * Attributes namespace; the interceptor's own name when no pointcut is given.
     */
    private function pointcut(string $module): string
    {
        $option = $this->option('pointcut');
        $name = ltrim(str_replace('/', '\\', is_string($option) && $option !== '' ? $option : class_basename($this->getNameInput())), '\\');

        if (class_exists($name) || interface_exists($name)) {
            return $name;
        }

        return str_starts_with($name, $this->rootNamespace()) ? $name : $this->rootNamespace().$module.'\\Attributes\\'.$name;
    }

    /** @param  class-string  $class  A loaded class or interface */
    private function isAttribute(string $class): bool
    {
        return (new ReflectionClass($class))->getAttributes(Attribute::class) !== [];
    }

    /** @return list<array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['module', 'm', InputOption::VALUE_REQUIRED, 'The module the interceptor belongs to'],
            ['pointcut', 'p', InputOption::VALUE_REQUIRED, 'The attribute, class or interface the interceptor applies to (default: an attribute named after the interceptor)'],
            ['around', null, InputOption::VALUE_NONE, 'An #[Around] interceptor taking a MethodInvocation (the default)'],
            ['before', null, InputOption::VALUE_NONE, 'A #[Before] interceptor'],
            ['after', null, InputOption::VALUE_NONE, 'An #[After] interceptor'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the interceptor (and a generated pointcut) if they already exist'],
        ];
    }
}
