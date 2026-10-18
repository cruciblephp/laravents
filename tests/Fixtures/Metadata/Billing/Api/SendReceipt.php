<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Metadata\Billing\Api;

final readonly class SendReceipt
{
    public function __construct(public int $amount) {}
}
