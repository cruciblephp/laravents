<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CClosure
{
    public function run(): callable
    {
        return fn (\Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TClosureParam $x): ?\Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TArrowReturn => null;
    }
}
