<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Event: a settlement ran. Its one handler is asynchronous and can be told to fail. */
final readonly class Settled
{
    public function __construct(public int $amount) {}
}
