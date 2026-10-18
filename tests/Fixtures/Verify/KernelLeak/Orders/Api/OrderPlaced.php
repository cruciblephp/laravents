<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\KernelLeak\Orders\Api;

use Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Api\Money;

final readonly class OrderPlaced
{
    public function __construct(public int $orderId, public Money $total) {}
}
