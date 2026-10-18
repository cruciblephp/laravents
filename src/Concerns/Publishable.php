<?php

declare(strict_types=1);

namespace Laravents\Concerns;

use Laravents\Contracts\EventBus;

/**
 * Opt-in entry point: NicknameGiven::publish(...) takes the constructor's arguments, named or positional.
 */
trait Publishable
{
    public static function publish(mixed ...$arguments): void
    {
        app(EventBus::class)->publish(new static(...$arguments)); // @phpstan-ignore argument.type (the arguments are the constructor's own, checked by PHP at call time, as in Laravel's Dispatchable)
    }
}
