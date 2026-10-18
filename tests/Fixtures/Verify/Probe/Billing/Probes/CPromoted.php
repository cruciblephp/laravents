<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final readonly class CPromoted
{
    public function __construct(public \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TPromoted $x, public object $y = new \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TDefaultNew()) {}
}
