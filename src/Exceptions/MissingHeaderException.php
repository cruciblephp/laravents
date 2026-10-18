<?php

declare(strict_types=1);

namespace Laravents\Exceptions;

use Laravents\Discovery\HandlerDefinition;
use LogicException;

final class MissingHeaderException extends LogicException
{
    public static function for(string $header, HandlerDefinition $handler): self
    {
        return new self(sprintf('Header [%s] is missing for %s.', $header, $handler->location()));
    }
}
