<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Testing\Billing\Api;

final readonly class InvoicePaid
{
    public function __construct(public int $invoiceId) {}
}
