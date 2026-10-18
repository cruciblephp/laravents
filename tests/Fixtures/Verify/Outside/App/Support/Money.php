<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Outside\App\Support;

use Laravents\Tests\Fixtures\Verify\Outside\Modules\Orders\Api\OrderTotal;

final class Money
{
    public static function format(OrderTotal $total): string
    {
        return number_format($total->cents / 100, 2);
    }
}
