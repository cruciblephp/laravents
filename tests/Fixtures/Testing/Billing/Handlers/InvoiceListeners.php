<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Testing\Billing\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Recorder;
use Laravents\Tests\Fixtures\Testing\Billing\Api\InvoicePaid;

/**
 * A keyed event handler that takes a class, so an array published by key is hydrated.
 */
final class InvoiceListeners
{
    #[EventHandler('billing.invoice_paid')]
    public function onPaid(InvoicePaid $event): void
    {
        Recorder::record(__FUNCTION__, $event);
    }
}
