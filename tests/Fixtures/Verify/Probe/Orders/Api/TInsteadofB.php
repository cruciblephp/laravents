<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Probe\Orders\Api;

trait TInsteadofB
{
    public function tinsteadofb(): int
    {
        return 1;
    }

    public function shared(): int
    {
        return 1;
    }
}
