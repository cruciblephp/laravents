<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Cache\Duplicate;

use Laravents\Attributes\CommandHandler;

final class FirstHandlers
{
    /** @param array<string, mixed> $payload */
    #[CommandHandler('cache.ping')]
    public function ping(array $payload): void {}
}
