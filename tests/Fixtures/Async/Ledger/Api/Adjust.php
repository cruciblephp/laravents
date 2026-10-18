<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.adjust: #[Transactional(connection: other)] around a nested transaction on `other`. */
final readonly class Adjust
{
    public function __construct(public int $amount, public bool $fail = false) {}
}
