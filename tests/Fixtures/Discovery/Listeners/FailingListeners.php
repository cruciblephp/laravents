<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Listeners;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderCancelled;
use RuntimeException;

final class FailingListeners
{
    #[EventHandler]
    public function onCancelled(OrderCancelled $event): never
    {
        throw new RuntimeException('Listener failed.');
    }
}
