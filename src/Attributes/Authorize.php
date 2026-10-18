<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Authorizes the actor through the Gate before a command or query handler runs. Not allowed on event handlers.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Authorize
{
    public function __construct(public ?string $ability = null) {}
}
