<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Native\Audit\Api;

final readonly class Shipped
{
    public function __construct(public int $orderId) {}
}
