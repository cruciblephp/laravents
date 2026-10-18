<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CAttrArg
{
    #[\Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes\Note(\Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TAttrArg::MAX)]
    public function run(): void {}
}
