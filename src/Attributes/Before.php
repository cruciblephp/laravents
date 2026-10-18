<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Runs before the handler, then lets the chain go on.
 * Applies to every handler the pointcut matches. Lower precedence runs first, outermost.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Before
{
    /** @param class-string $pointcut An attribute on the handler method or class, or a class or interface of the handler */
    public function __construct(
        public string $pointcut,
        public int $precedence = 0,
    ) {}
}
