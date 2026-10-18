<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Billing\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\{Audited, HasOrder, OrderFailed};
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\OrderPlaced as Placed;
use Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\ReadsOrders;

/**
 * The clean side: Billing reaches Orders only through Orders\Api, by every construct the scanner reads. Orders'
 * internals are named only where the scanner must not look: this docblock (Laravents\Tests\Fixtures\Verify\Clean\Orders\Models\Order), a
 * comment and a string.
 */
final class ChargeOnOrderPlaced implements HasOrder
{
    use ReadsOrders;

    public ?Api\OrderStatus $status = null;

    #[EventHandler]
    #[Audited]
    public function __invoke(Placed $event): void
    {
        // Laravents\Tests\Fixtures\Verify\Clean\Orders\Models\Order is internal.
        $this->status = $event->status;
    }

    public function orderId(): int
    {
        return \Laravents\Tests\Fixtures\Verify\Clean\Orders\Api\OrderLimits::MAX;
    }

    public function archived(object $event): ?Api\Events\OrderArchived
    {
        return $event instanceof Api\Events\OrderArchived ? $event : null;
    }

    public function charge(Placed $event): namespace\Ledger
    {
        try {
            return new namespace\Ledger($event->orderId);
        } catch (OrderFailed) {
            return new Ledger(0);
        }
    }

    /** @return list<string> */
    public function internals(): array
    {
        return ['Laravents\Tests\Fixtures\Verify\Clean\Orders\Models\Order'];
    }
}
