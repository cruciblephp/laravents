<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Direction\Billing\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Verify\Direction\Orders\Api\OrderPlaced;

final class ChargeOnOrderPlaced
{
    #[EventHandler]
    public function __invoke(OrderPlaced $event): int
    {
        return (new \Laravents\Tests\Fixtures\Verify\Direction\Orders\Models\Order($event->orderId))->id;
    }
}
