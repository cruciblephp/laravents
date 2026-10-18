<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Native\Settlement\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Tests\Fixtures\NativeApp\Events\InvoiceSettled;
use Laravents\Tests\Fixtures\NativeApp\Events\Settles;
use Laravents\Tests\Fixtures\Recorder;

/** Handlers of an application event's class and of an interface it implements: Laravel calls both subscriptions. */
final class SettlementListeners
{
    #[EventHandler]
    public function byClass(InvoiceSettled $event, #[Header('message_id')] string $messageId): void
    {
        Recorder::record('class', $messageId);
    }

    #[EventHandler]
    public function byInterface(Settles $event, #[Header('message_id')] string $messageId): void
    {
        Recorder::record('interface', $messageId);
    }
}
