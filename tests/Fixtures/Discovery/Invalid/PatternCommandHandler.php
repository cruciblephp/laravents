<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;

final class PatternCommandHandler
{
    /** @param array<string, mixed> $command */
    #[CommandHandler('shop.*')]
    public function handle(array $command): void {}
}
