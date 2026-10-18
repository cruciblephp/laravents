<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\CommandHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;

final class AsynchronousCommandHandler
{
    #[CommandHandler]
    #[Asynchronous]
    public function handle(PlaceOrder $command): void {}
}
