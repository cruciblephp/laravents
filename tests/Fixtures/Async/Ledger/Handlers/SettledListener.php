<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Settled;
use Laravents\Tests\Fixtures\Recorder;
use RuntimeException;

final class SettledListener
{
    /** How many runs still throw before one succeeds. */
    public static int $failures = 0;

    /** Records, per attempt, whether its publication was already completed when the attempt started. */
    #[EventHandler]
    #[Asynchronous]
    public function onSettled(Settled $event, #[Header('publication_id')] string $publicationId): void
    {
        Recorder::record('onSettled: completed_at', EventPublication::query()->findOrFail($publicationId)->completed_at);

        if (self::$failures > 0) {
            self::$failures--;

            throw new RuntimeException('Settlement failed.');
        }
    }
}
