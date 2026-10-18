<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Orders\Api;

final readonly class OrderPlaced
{
    public function __construct(public int $orderId, public OrderStatus $status) {}
}
