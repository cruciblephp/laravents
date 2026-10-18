<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Cycle\Orders\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Verify\Cycle\Billing\Api\InvoicePaid;

final class MarkOrderPaid
{
    #[EventHandler]
    public function __invoke(InvoicePaid $event): void {}
}
