<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Support;

final class Rounding
{
    public static function cents(int $amount): int
    {
        return $amount;
    }
}
