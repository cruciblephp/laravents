<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CAnon
{
    public function run(): object
    {
        return new class extends \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TAnonExtends {};
    }
}
