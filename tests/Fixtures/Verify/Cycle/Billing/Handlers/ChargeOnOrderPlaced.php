<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Cycle\Billing\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Verify\Cycle\Orders\Api\OrderPlaced;

final class ChargeOnOrderPlaced
{
    #[EventHandler]
    public function __invoke(OrderPlaced $event): void {}
}
