<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\QueryHandler;

enum HandlerKind: string
{
    case Command = 'command';
    case Query = 'query';
    case Event = 'event';

    /** @return class-string<CommandHandler|QueryHandler|EventHandler> */
    public function attribute(): string
    {
        return match ($this) {
            self::Command => CommandHandler::class,
            self::Query => QueryHandler::class,
            self::Event => EventHandler::class,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
