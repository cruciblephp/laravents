<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;

final class UnkeyedArrayCommand
{
    /** @param array<string, mixed> $payload */
    #[CommandHandler]
    public function handle(array $payload): void {}
}
