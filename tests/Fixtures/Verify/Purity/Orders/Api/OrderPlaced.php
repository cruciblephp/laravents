<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Purity\Orders\Api;

use Laravents\Tests\Fixtures\Verify\Purity\Orders\Models\Order;

final readonly class OrderPlaced
{
    public function __construct(public Order $order) {}
}
