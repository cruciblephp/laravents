<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;

final class DuplicateMessageClass
{
    #[CommandHandler('shop.place_again')]
    public function again(PlaceOrder $command): void {}
}
