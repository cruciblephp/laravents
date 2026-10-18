<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Outside\App\Support;

use Laravents\Tests\Fixtures\Verify\Outside\Modules\Orders\Models\Order;

final class Receipts
{
    public static function number(Order $order): string
    {
        return sprintf('R-%06d', $order->id);
    }
}
