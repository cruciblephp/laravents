<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Orders\Api;

trait ReadsOrders
{
    public function view(int $id): OrderView
    {
        return new OrderView($id);
    }
}
