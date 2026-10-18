<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\Events;

final readonly class OrderArchived
{
    public function __construct(public int $orderId) {}
}
