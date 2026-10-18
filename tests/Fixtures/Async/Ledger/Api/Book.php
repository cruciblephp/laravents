<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.book: sends ledger.post inside its own transaction, then fails if asked to. */
final readonly class Book
{
    public function __construct(public int $amount, public bool $fail = false) {}
}
