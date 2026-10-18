<?php

declare(strict_types=1);

namespace Laravents\Exceptions;

use LogicException;

/**
 * The actor headers name a user the guard has not authenticated: something put the wrong actor in Context.
 */
final class ActorMismatchException extends LogicException
{
    public static function for(string $guard, string $id): self
    {
        return new self(sprintf('The actor [%s:%s] is not the user authenticated on that guard.', $guard, $id));
    }
}
