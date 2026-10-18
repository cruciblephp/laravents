<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CClassConst
{
    public function run(): string
    {
        return \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TClassConst::class;
    }
}
