<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CCatch
{
    public function run(callable $f): void
    {
        try {
            $f();
        } catch (\Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TCatch|\Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TCatchB) {
        }
    }
}
