<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Native\Audit\Handlers;

use Illuminate\Auth\Events\Login;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Tests\Fixtures\Native\Audit\Api\Shipped;
use Laravents\Tests\Fixtures\NativeApp\Events\OrderShipped;
use Laravents\Tests\Fixtures\Recorder;

/** Three handlers of one framework event, one of an application event, and module events Laravel never reaches. */
final class AuditListeners
{
    #[EventHandler]
    public function first(Login $event, #[Header('message_id')] string $messageId, #[Header('correlation_id')] string $correlationId, #[Header('actor_id')] ?string $actorId = null): void
    {
        Recorder::record('first', [$messageId, $correlationId, $actorId]);
    }

    #[EventHandler]
    public function second(Login $event, #[Header('message_id')] string $messageId, #[Header('correlation_id')] string $correlationId, #[Header('actor_id')] ?string $actorId = null): void
    {
        Recorder::record('second', [$messageId, $correlationId, $actorId]);
    }

    #[EventHandler]
    public function third(Login $event, #[Header('message_id')] string $messageId, #[Header('correlation_id')] string $correlationId, #[Header('actor_id')] ?string $actorId = null): void
    {
        Recorder::record('third', [$messageId, $correlationId, $actorId]);
    }

    #[EventHandler]
    public function shipped(OrderShipped $event): void
    {
        Recorder::record('order shipped', $event->orderId);
    }

    #[EventHandler]
    public function moduleShipped(Shipped $event): void
    {
        Recorder::record('module shipped', $event->orderId);
    }

    /** @param array<string, mixed> $payload */
    #[EventHandler('audit.login')]
    public function keyed(array $payload): void
    {
        Recorder::record('keyed', $payload);
    }
}
