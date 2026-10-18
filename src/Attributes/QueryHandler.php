<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Marks the one handler of a query. Without a key, the key is derived from the first parameter's class.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class QueryHandler
{
    public function __construct(public ?string $routingKey = null) {}
}
