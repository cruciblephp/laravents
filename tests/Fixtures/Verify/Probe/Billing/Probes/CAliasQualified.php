<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

use Laravents\Tests\Fixtures\Verify\Probe\Orders\Api;

final class CAliasQualified
{
    public function run(): int
    {
        return Api\Deep\TAliasQualified::MAX + Api\TStaticAlias::MAX;
    }
}
