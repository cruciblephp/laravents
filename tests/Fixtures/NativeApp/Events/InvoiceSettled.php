<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\NativeApp\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvoiceSettled implements Settles
{
    use Dispatchable;

    public function __construct(public int $invoiceId) {}
}
