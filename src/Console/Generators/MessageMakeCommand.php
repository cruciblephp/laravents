<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Laravents\Discovery\RoutingKey;
use Symfony\Component\Console\Input\InputOption;

/**
 * Writes a message into the module's Api namespace and one handler class for it into Handlers, as make:event
 * and make:listener do: one handler class per message, so the generator never edits an existing class.
 * Refuses, writing nothing, when either file exists, unless --force.
 */
abstract class MessageMakeCommand extends ModuleGeneratorCommand
{
    use RequiresModule;

    /** The stub of the message, e.g. command.stub. */
    abstract protected function messageStub(): string;

    /** The stub of the handler, e.g. command-handler.stub. */
    abstract protected function handlerStub(): string;

    /**
     * The attributes the options add to the handler method, as class-string => attribute source.
     *
     * @return array<class-string, string>
     */
    abstract protected function handlerAttributes(): array;

    protected function getStub(): string
    {
        return $this->resolveStubPath($this->messageStub());
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\'.$this->moduleInput().'\\Api';
    }

    public function handle(): ?bool
    {
        if ($this->isReservedName($this->getNameInput())) {
            $this->components->error('The name "'.$this->getNameInput().'" is reserved by PHP.');

            return false;
        }

        $module = $this->existingModule();

        if ($module === null) {
            return false;
        }

        $api = $this->rootNamespace().$module.'\\Api\\';
        $message = $this->qualifyClass($this->getNameInput());

        if (! str_starts_with($message, $api)) {
            $this->components->error(sprintf('A %s belongs in [%s].', strtolower($this->type), rtrim($api, '\\')));

            return false;
        }

        $handler = $this->rootNamespace().$module.'\\Handlers\\'.substr($message, strlen($api)).'Handler';
        $messagePath = $this->getPath($message);
        $handlerPath = $this->getPath($handler);

        if (! $this->force()) {
            foreach ([$messagePath => $this->type, $handlerPath => 'Handler'] as $path => $type) {
                if ($this->files->exists($path)) {
                    $this->components->error(sprintf('%s [%s] already exists.', $type, $path));

                    return false;
                }
            }
        }

        $this->write($messagePath, $this->render($this->messageStub(), $message, [
            'routingKey' => (string) RoutingKey::derive($message, $this->rootNamespace()),
            'module' => $module,
        ]));

        $attributes = $this->handlerAttributes();
        $this->write($handlerPath, $this->render($this->handlerStub(), $handler, [
            'namespacedMessage' => $message,
            'message' => class_basename($message),
            'imports' => implode('', array_map(static fn (string $class): string => "\nuse ".$class.';', array_keys($attributes))),
            'attributes' => implode('', array_map(static fn (string $attribute): string => "\n    ".$attribute, $attributes)),
        ]));

        $this->components->info(sprintf('%s [%s] created successfully.', $this->type, $messagePath));
        $this->components->info(sprintf('Handler [%s] created successfully.', $handlerPath));

        return null;
    }

    /** @return list<array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['module', 'm', InputOption::VALUE_REQUIRED, 'The module the '.strtolower($this->type).' belongs to'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the '.strtolower($this->type).' and its handler if they already exist'],
        ];
    }
}
