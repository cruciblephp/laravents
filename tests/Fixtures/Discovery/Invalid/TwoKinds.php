<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\PlaceOrder;

final class TwoKinds
{
    #[CommandHandler]
    #[QueryHandler]
    public function handle(PlaceOrder $message): void {}
}
