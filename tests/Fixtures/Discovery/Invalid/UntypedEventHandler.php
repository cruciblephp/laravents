<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\EventHandler;

final class UntypedEventHandler
{
    /** @param array<string, mixed> $event */
    #[EventHandler]
    public function on(array $event): void {}
}
