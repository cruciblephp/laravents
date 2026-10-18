<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Outside\Modules\Orders\Api;

final readonly class OrderTotal
{
    public function __construct(public int $orderId, public int $cents) {}
}
