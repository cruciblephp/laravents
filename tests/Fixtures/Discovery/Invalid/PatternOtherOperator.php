<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\EventHandler;

final class PatternOtherOperator
{
    #[EventHandler('chat.open?')]
    public function on(mixed $event): void {}
}
