<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CNew
{
    public function run(): object
    {
        return new \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TNew();
    }
}
