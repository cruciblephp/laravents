<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Illuminate\Support\Facades\DB;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Transactional;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Adjust;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Clear;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Remit;
use Laravents\Tests\Fixtures\Recorder;
use RuntimeException;

/** Publishers whose transactions are written by hand rather than declared with #[Transactional]. */
final class HandWrittenHandlers
{
    #[CommandHandler]
    public function remit(Remit $command, EventBus $events): void
    {
        DB::connection('other')->transaction(static function () use ($command, $events): void {
            $events->publish(new Posted($command->amount));

            if ($command->fail) {
                throw new RuntimeException('Remit failed.');
            }
        });
    }

    #[CommandHandler]
    #[Transactional]
    public function clear(Clear $command, EventBus $events): void
    {
        DB::connection('other')->transaction(static function () use ($command, $events): void {
            $events->publish(new Posted($command->amount));
        });

        Recorder::record('clear: jobs after other committed', DB::table('jobs')->count());

        if ($command->fail) {
            throw new RuntimeException('Clear failed.');
        }
    }

    #[CommandHandler]
    #[Transactional(connection: 'other')]
    public function adjust(Adjust $command, EventBus $events): void
    {
        DB::connection('other')->transaction(static function () use ($command, $events): void {
            Recorder::record('adjust: other transaction level', DB::connection('other')->transactionLevel());
            $events->publish(new Posted($command->amount));
        });

        if ($command->fail) {
            throw new RuntimeException('Adjust failed.');
        }
    }
}
