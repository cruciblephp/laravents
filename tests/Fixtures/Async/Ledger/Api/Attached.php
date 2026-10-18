<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Event: a file was attached, its raw bytes carried as they are, which is what the outbox refuses. */
final readonly class Attached
{
    public function __construct(public string $bytes) {}
}
