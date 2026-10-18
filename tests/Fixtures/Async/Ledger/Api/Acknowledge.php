<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.acknowledge: sent by the asynchronous handler, so its metadata shows the chain across the queue. */
final readonly class Acknowledge
{
    public function __construct(public int $amount) {}
}
