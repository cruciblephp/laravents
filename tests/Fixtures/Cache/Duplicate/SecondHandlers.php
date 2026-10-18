<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Cache\Duplicate;

use Laravents\Attributes\CommandHandler;

final class SecondHandlers
{
    /** @param array<string, mixed> $payload */
    #[CommandHandler('cache.ping')]
    public function again(array $payload): void {}
}
