<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Shop\Api;

readonly class OrderPlaced implements OrderEvent
{
    public function __construct(public int $orderId) {}
}
