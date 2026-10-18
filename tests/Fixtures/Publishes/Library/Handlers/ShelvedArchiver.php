<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Library\Handlers;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookShelved;
use Laravents\Tests\Fixtures\Recorder;

/** An asynchronous listener of the model's event, so a shelved Book writes an outbox row. */
final class ShelvedArchiver
{
    #[EventHandler]
    #[Asynchronous]
    public function archive(BookShelved $event): void
    {
        Recorder::record('archived', $event);
    }
}
