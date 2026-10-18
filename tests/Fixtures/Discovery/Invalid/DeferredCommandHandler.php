<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Deferred;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;

final class DeferredCommandHandler
{
    #[CommandHandler]
    #[Deferred]
    public function handle(PlaceOrder $command): void {}
}
