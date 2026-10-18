<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Shop\Handlers;

use DomainException;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\DeclineOrder;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\FindOrder;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;

final class ShopHandlers
{
    #[CommandHandler]
    public function place(PlaceOrder $command): string
    {
        return $command->sku;
    }

    #[QueryHandler]
    public function find(FindOrder $query): int
    {
        return $query->id;
    }

    #[CommandHandler]
    public function decline(DeclineOrder $command): never
    {
        throw new DomainException('Card declined.');
    }
}
