<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\Deferred;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;

final class DeferredAsynchronousHandler
{
    #[EventHandler]
    #[Deferred]
    #[Asynchronous]
    public function handle(OrderPlaced $event): void {}
}
