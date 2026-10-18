<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Clean\Orders\Api;

interface HasOrder
{
    public function orderId(): int;
}
