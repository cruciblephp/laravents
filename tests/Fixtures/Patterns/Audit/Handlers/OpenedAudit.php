<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Patterns\Audit\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Recorder;

/** A third module that names no other module's class: it reaches their events by key. */
final class OpenedAudit
{
    #[EventHandler('*.opened')]
    public function anythingOpened(mixed $event): void
    {
        Recorder::record('*.opened', $event);
    }

    /** No channel, so it queues on its module: audit. */
    #[EventHandler('room.*')]
    #[Asynchronous]
    public function roomLater(mixed $event, #[CurrentUser] ?Authenticatable $user): void
    {
        Recorder::record('room.* later', [$event, $user?->getAuthIdentifier()]);
    }
}
