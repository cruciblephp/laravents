<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\CommandHandler;

final class OutsideRootCommand
{
    #[CommandHandler]
    public function handle(\stdClass $command): void {}
}
