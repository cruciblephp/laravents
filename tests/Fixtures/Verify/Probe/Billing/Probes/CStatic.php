<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CStatic
{
    public function run(): int
    {
        return \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TStatic::make();
    }
}
