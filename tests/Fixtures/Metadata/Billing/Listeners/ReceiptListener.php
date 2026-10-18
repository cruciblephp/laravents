<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Metadata\Billing\Listeners;

use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Contracts\CommandBus;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Charged;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\SendReceipt;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;

final class ReceiptListener
{
    #[EventHandler]
    public function onCharged(
        Charged $event,
        CommandBus $commands,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
    ): void {
        Recorder::record('onCharged', Ids::capture($messageId, $correlationId, $causationId));
        $commands->dispatch(new SendReceipt($event->amount));
    }
}
