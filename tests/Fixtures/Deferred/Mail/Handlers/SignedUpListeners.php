<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Deferred\Mail\Handlers;

use Illuminate\Support\Facades\Auth;
use Laravents\Attributes\Deferred;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Deferred\Mail\Api\SignedUp;
use Laravents\Tests\Fixtures\Deferred\Mail\Api\WelcomeSent;
use Laravents\Tests\Fixtures\Deferred\Mail\Attributes\Stamped;
use Laravents\Tests\Fixtures\Recorder;
use RuntimeException;

final class SignedUpListeners
{
    #[EventHandler]
    public function now(SignedUp $event, #[Header('message_id')] string $messageId): void
    {
        Recorder::record('now', $messageId);
    }

    /** Records the ids it saw and the user on the guard, then publishes a follow-up whose causation_id is this event. */
    #[EventHandler]
    #[Deferred]
    #[Stamped]
    public function later(SignedUp $event, EventBus $events, #[Header('message_id')] string $messageId, #[Header('correlation_id')] string $correlationId): void
    {
        Recorder::record('later', ['message_id' => $messageId, 'correlation_id' => $correlationId, 'user' => Auth::id()]);

        if ($event->failLater) {
            throw new RuntimeException('The welcome mail could not be sent.');
        }

        $events->publish(new WelcomeSent($event->email));
    }

    #[EventHandler]
    #[Deferred]
    public function alsoLater(SignedUp $event): void
    {
        Recorder::record('alsoLater', $event->email);
    }

    #[EventHandler]
    public function welcomed(WelcomeSent $event, #[Header('causation_id')] string $causationId): void
    {
        Recorder::record('welcomed', $causationId);
    }
}
