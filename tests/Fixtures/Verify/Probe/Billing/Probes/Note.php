<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

use Attribute;

#[Attribute]
final readonly class Note
{
    public function __construct(public int $value) {}
}
