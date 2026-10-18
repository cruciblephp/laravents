<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Illuminate\Support\Facades\DB;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Transactional;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Transfer;
use Laravents\Tests\Fixtures\Recorder;
use RuntimeException;

final class TransferHandlers
{
    /** A publisher whose transaction is on `other`, not on the default connection. */
    #[CommandHandler]
    #[Transactional(connection: 'other')]
    public function transfer(Transfer $command, EventBus $events): void
    {
        $events->publish(new Posted($command->amount));

        Recorder::record('transfer: other transaction level', DB::connection('other')->transactionLevel());

        if ($command->fail) {
            throw new RuntimeException('Transfer failed.');
        }
    }
}
