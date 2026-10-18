<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\NativeApp\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** An application event, outside the modules namespace, in the shape of App\Events\OrderShipped. */
final readonly class OrderShipped
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
