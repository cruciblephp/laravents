<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Listeners;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\ExpressOrderPlaced;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderEvent;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;
use Laravents\Tests\Fixtures\Recorder;

final class OrderListeners
{
    #[EventHandler]
    public function onAnyOrderEvent(OrderEvent $event): void
    {
        Recorder::record(__FUNCTION__, $event);
    }

    #[EventHandler]
    public function onPlaced(OrderPlaced $event): void
    {
        Recorder::record(__FUNCTION__, $event);
    }

    #[EventHandler]
    public function onExpress(ExpressOrderPlaced $event): void
    {
        Recorder::record(__FUNCTION__, $event);
    }

    /** @param array<string, mixed> $payload */
    #[EventHandler('shop.legacy_order_imported')]
    public function onLegacyImport(array $payload): void
    {
        Recorder::record(__FUNCTION__, $payload);
    }

    #[EventHandler]
    #[Asynchronous]
    public function later(OrderPlaced $event): void
    {
        Recorder::record(__FUNCTION__, $event);
    }

    #[EventHandler]
    #[Asynchronous('mail')]
    public function mail(OrderPlaced $event): void
    {
        Recorder::record(__FUNCTION__, $event);
    }
}
