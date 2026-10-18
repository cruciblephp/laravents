<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Library\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookRemoved;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookShelved;
use Laravents\Tests\Fixtures\Recorder;

final class LibraryListeners
{
    #[EventHandler]
    public function shelved(BookShelved $event, #[Header('correlation_id')] string $correlationId, #[Header('message_id')] string $messageId): void
    {
        Recorder::record('shelved', [$event, $correlationId === $messageId]);
    }

    #[EventHandler]
    public function removed(BookRemoved $event): void
    {
        Recorder::record('removed', $event->label);
    }
}
