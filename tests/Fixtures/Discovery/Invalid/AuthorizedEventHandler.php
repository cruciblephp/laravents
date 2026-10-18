<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\Authorize;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;

final class AuthorizedEventHandler
{
    #[EventHandler]
    #[Authorize('orders.watch')]
    public function on(OrderPlaced $event): void {}
}
