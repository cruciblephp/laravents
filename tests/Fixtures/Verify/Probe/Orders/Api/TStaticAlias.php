<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Orders\Api;

class TStaticAlias
{
    public const int MAX = 1;

    public static function make(): int
    {
        return 1;
    }
}
