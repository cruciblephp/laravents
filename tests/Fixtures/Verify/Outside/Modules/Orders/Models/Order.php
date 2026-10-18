<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Outside\Modules\Orders\Models;

final readonly class Order
{
    public function __construct(public int $id) {}
}
