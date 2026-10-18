<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Laravents\Attributes\Authorize;
use Laravents\Attributes\Transactional;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'laravents:command')]
final class CommandMakeCommand extends MessageMakeCommand
{
    protected $name = 'laravents:command';

    protected $description = 'Create a command message and its handler in a module';

    protected $type = 'Command';

    protected function messageStub(): string
    {
        return 'command.stub';
    }

    protected function handlerStub(): string
    {
        return 'command-handler.stub';
    }

    protected function handlerAttributes(): array
    {
        return array_filter([
            Authorize::class => $this->option('authorize') === true ? '#[Authorize]' : null,
            Transactional::class => $this->option('transactional') === true ? '#[Transactional]' : null,
        ]);
    }

    protected function getOptions(): array
    {
        return [
            ...parent::getOptions(),
            ['authorize', null, InputOption::VALUE_NONE, 'Authorize the actor through the Gate before the handler runs'],
            ['transactional', null, InputOption::VALUE_NONE, 'Run the handler in a database transaction'],
        ];
    }
}
