<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.attach: writes an entry and publishes Attached inside its transaction. */
final readonly class Attach
{
    public function __construct(public string $bytes) {}
}
