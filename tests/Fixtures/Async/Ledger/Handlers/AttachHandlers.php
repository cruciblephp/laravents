<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Transactional;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Attach;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Attached;
use Laravents\Tests\Fixtures\Async\Ledger\Entries;

final class AttachHandlers
{
    #[CommandHandler]
    #[Transactional]
    public function attach(Attach $command, EventBus $events): void
    {
        Entries::write(1);
        $events->publish(new Attached($command->bytes));
    }
}
