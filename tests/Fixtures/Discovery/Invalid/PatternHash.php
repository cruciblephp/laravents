<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\EventHandler;

final class PatternHash
{
    #[EventHandler('chat.#')]
    public function on(mixed $event): void {}
}
