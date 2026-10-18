<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

abstract class CReturn
{
    abstract public function run(): ?\Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TReturn;
}
