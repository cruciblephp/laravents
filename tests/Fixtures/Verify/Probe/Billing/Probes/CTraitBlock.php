<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Billing\Probes;

final class CTraitBlock
{
    use \Laravents\Tests\Fixtures\Verify\Probe\Orders\Api\TTraitBlock {
        ttraitblock as protected aliased;
    }
}
