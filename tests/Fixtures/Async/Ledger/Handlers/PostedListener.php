<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Contracts\CommandBus;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Acknowledge;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;

final class PostedListener
{
    /** No channel, so it queues on its module: ledger. */
    #[EventHandler]
    #[Asynchronous]
    public function onPosted(
        Posted $event,
        CommandBus $commands,
        #[CurrentUser] ?Authenticatable $user,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
        #[Header('tenant')] ?string $tenant,
    ): void {
        Recorder::record('onPosted', Ids::capture($messageId, $correlationId, $causationId));
        Recorder::record('onPosted: tenant', $tenant);
        Recorder::record('onPosted: user', $user?->getAuthIdentifier());
        $commands->dispatch(new Acknowledge($event->amount));
    }
}
