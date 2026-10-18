<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Event: a receipt was issued. Its state is private and protected, so serialize() writes NUL bytes. */
final class Receipted
{
    public function __construct(private readonly int $amount, protected string $memo) {}

    public function amount(): int
    {
        return $this->amount;
    }

    public function memo(): string
    {
        return $this->memo;
    }
}
