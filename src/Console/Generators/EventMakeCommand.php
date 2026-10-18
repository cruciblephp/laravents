<?php

declare(strict_types=1);

namespace Laravents\Console\Generators;

use Laravents\Attributes\Asynchronous;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'laravents:event')]
final class EventMakeCommand extends MessageMakeCommand
{
    protected $name = 'laravents:event';

    protected $description = 'Create an event and a handler for it in a module';

    protected $type = 'Event';

    protected function messageStub(): string
    {
        return 'event.stub';
    }

    protected function handlerStub(): string
    {
        return 'event-handler.stub';
    }

    /** --async without a value is #[Asynchronous] (the module's queue); --async=name names the queue. */
    protected function handlerAttributes(): array
    {
        $async = $this->option('async');

        if ($async === false) {
            return [];
        }

        return [Asynchronous::class => is_string($async) && $async !== '' ? sprintf('#[Asynchronous(%s)]', var_export($async, true)) : '#[Asynchronous]'];
    }

    protected function getOptions(): array
    {
        return [
            ...parent::getOptions(),
            ['async', null, InputOption::VALUE_OPTIONAL, 'Run the handler on a queue after commit; optionally name the queue', false],
        ];
    }
}
