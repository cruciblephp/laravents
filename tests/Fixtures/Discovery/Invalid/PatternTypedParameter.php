<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;

final class PatternTypedParameter
{
    #[EventHandler('shop.*')]
    public function on(OrderPlaced $event): void {}
}
