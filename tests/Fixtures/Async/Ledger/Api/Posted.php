<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Event: an entry was written. Its one handler is asynchronous. */
final readonly class Posted
{
    public function __construct(public int $amount) {}
}
