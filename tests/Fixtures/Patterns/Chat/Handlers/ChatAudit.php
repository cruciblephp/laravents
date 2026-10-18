<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Patterns\Chat\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Recorder;

/**
 * The pattern handler is discovered before the exact one, so "exact first" is the registry's order, not discovery's.
 */
final class ChatAudit
{
    /** @param object|array<string, mixed> $event */
    #[EventHandler('chat.*')]
    public function everyChatEvent(object|array $event): void
    {
        Recorder::record('chat.*', $event);
    }

    /** @param array<string, mixed> $event */
    #[EventHandler('chat.open')]
    public function onOpen(array $event): void
    {
        Recorder::record('chat.open', $event);
    }
}
