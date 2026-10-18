<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;

final class DuplicateCommandKey
{
    /** @param array<string, mixed> $payload */
    #[CommandHandler('shop.place_order')]
    public function again(array $payload): void {}
}
