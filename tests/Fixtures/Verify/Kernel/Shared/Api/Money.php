<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Kernel\Shared\Api;

/** The shared kernel: a value object every module may use, through Shared\Api. */
final readonly class Money
{
    public function __construct(public int $amount, public Currency $currency) {}
}
