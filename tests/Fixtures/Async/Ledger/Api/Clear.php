<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.clear: #[Transactional] on the default connection around a hand-written `other` transaction. */
final readonly class Clear
{
    public function __construct(public int $amount, public bool $fail = false) {}
}
