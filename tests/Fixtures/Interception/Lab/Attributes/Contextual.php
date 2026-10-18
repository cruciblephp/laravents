<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Contextual
{
    public function __construct() {}
}
