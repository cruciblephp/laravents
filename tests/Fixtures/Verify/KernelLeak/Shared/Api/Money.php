<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Api;

use Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Support\Rounding;

/** The shared kernel's Api leaning on its own internals: an Api purity violation. */
final readonly class Money
{
    public int $amount;

    public function __construct(int $amount)
    {
        $this->amount = Rounding::cents($amount);
    }
}
