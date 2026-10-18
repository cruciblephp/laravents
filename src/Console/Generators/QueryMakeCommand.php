<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Laravents\Attributes\Authorize;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'laravents:query')]
final class QueryMakeCommand extends MessageMakeCommand
{
    protected $name = 'laravents:query';

    protected $description = 'Create a query message and its handler in a module';

    protected $type = 'Query';

    protected function messageStub(): string
    {
        return 'query.stub';
    }

    protected function handlerStub(): string
    {
        return 'query-handler.stub';
    }

    protected function handlerAttributes(): array
    {
        return array_filter([
            Authorize::class => $this->option('authorize') === true ? '#[Authorize]' : null,
        ]);
    }

    protected function getOptions(): array
    {
        return [
            ...parent::getOptions(),
            ['authorize', null, InputOption::VALUE_NONE, 'Authorize the actor through the Gate before the handler runs'],
        ];
    }
}
