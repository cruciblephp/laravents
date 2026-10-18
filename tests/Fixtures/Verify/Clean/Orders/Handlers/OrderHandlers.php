<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Orders\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\OrderPlaced;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\OrderStatus;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\OrderView;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\PlaceOrder;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Models\Order;

final class OrderHandlers
{
    #[CommandHandler]
    public function place(PlaceOrder $command, EventBus $events): OrderView
    {
        $order = new Order($command->orderId);
        $events->publish(new OrderPlaced($order->id, OrderStatus::Placed));

        return new OrderView($order->id);
    }
}
