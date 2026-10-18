<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.remit: no #[Transactional]; publishes Posted inside a hand-written transaction on `other`. */
final readonly class Remit
{
    public function __construct(public int $amount, public bool $fail = false) {}
}
