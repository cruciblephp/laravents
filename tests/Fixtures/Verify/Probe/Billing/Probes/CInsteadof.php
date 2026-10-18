<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CInsteadof
{
    use \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TInsteadofA, \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TInsteadofB {
        \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TInsteadofA::shared insteadof \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TInsteadofB;
    }
}
