<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CInstanceof
{
    public function run(object $x): bool
    {
        return $x instanceof \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TInstanceof;
    }
}
