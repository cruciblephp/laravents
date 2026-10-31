<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Attributes;

use Attribute;

/** A pointcut attribute with an enum argument: the registry cache rebuilds it through reflection, not from its arguments. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Tiered
{
    public function __construct(public Tier $tier) {}
}
