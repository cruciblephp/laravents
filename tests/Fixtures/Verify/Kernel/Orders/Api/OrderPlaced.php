<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Kernel\Orders\Api;

use Laravents\Tests\Fixtures\Verify\Kernel\Shared\Api\Money;

/** One module's Api carrying the shared kernel's type. */
final readonly class OrderPlaced
{
    public function __construct(public int $orderId, public Money $total) {}
}
