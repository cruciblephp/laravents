<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Orders\Api;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Paid = 'paid';
}
