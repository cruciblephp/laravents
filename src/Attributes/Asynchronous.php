<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Runs an event handler on a queue after commit. Without a channel, the queue is the handler's module in snake case.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Asynchronous
{
    public function __construct(public ?string $channel = null) {}
}
