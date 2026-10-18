<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Billing\Handlers;

final readonly class Ledger
{
    public function __construct(public int $orderId) {}
}
