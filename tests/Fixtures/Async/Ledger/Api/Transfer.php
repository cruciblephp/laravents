<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.transfer: publishes Posted inside a transaction on the `other` connection, then fails if asked to. */
final readonly class Transfer
{
    public function __construct(public int $amount, public bool $fail = false) {}
}
