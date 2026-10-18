<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Expose;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;

final class ExposedEventHandler
{
    #[EventHandler]
    #[Expose]
    public function on(OrderPlaced $event): void {}
}
