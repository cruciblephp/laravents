<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;

final class StaticHandler
{
    #[CommandHandler('shop.static')]
    public static function handle(PlaceOrder $command): void {}
}
