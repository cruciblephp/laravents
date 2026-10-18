<?php

declare(strict_types=1);

namespace Laravents\Invocation;

use Closure;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerDefinition;

/**
 * What an #[Around] interceptor wraps: the rest of the chain, ending in the handler.
 */
final readonly class MethodInvocation
{
    /** @param Closure(): mixed $proceed */
    public function __construct(
        private Closure $proceed,
        private Message $message,
        private HandlerDefinition $handler,
    ) {}

    public function proceed(): mixed
    {
        return ($this->proceed)();
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function handler(): HandlerDefinition
    {
        return $this->handler;
    }
}
