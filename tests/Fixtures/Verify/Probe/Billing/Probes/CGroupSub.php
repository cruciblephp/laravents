<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

use Laravents\Tests\Fixtures\Verify\Probe\Orders\{Api\TGroupSub};

final class CGroupSub
{
    public function run(): int
    {
        return TGroupSub::MAX;
    }
}
