<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Attached;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Receipted;
use Laravents\Tests\Fixtures\Recorder;

/** Asynchronous listeners whose events travel through the outbox payload column. */
final class PayloadListener
{
    #[EventHandler]
    #[Asynchronous]
    public function onReceipted(Receipted $event): void
    {
        Recorder::record('onReceipted', [$event->amount(), $event->memo()]);
    }

    #[EventHandler]
    #[Asynchronous]
    public function onAttached(Attached $event): void
    {
        Recorder::record('onAttached', $event->bytes);
    }

    /** @param array<string, mixed> $payload */
    #[EventHandler('ledger.imported')]
    #[Asynchronous]
    public function imported(array $payload): void
    {
        Recorder::record('imported', $payload);
    }
}
