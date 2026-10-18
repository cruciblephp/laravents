<?php

declare(strict_types=1);

namespace Laravents\Exceptions;

use Laravents\Discovery\HandlerDefinition;
use LogicException;

final class DiscoveryException extends LogicException
{
    public static function duplicate(string $what, HandlerDefinition $first, HandlerDefinition $second): self
    {
        return new self(sprintf('%s is handled by both %s and %s.', $what, $first->location(), $second->location()));
    }

    public static function invalid(string $location, string $reason): self
    {
        return new self(sprintf('%s %s.', $location, $reason));
    }
}
