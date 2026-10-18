<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Laravents\Contracts\EventBus as EventBusContract;
use Laravents\Discovery\HandlerKind;

final readonly class EventBus implements EventBusContract
{
    public function __construct(private Engine $engine) {}

    /**
     * Runs every handler whose parameter type the event is an instance of, then the pattern handlers covering its
     * derived key. Zero handlers is a no-op.
     *
     * @param  array<string, scalar|null>  $headers
     */
    public function publish(object $event, array $headers = []): void
    {
        $this->engine->dispatch(new Message(HandlerKind::Event, $event::class, $event, $headers));
    }

    /**
     * Runs the handlers registered for a routing key with #[EventHandler('key')], then the pattern handlers covering it.
     *
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function publishTo(string $routingKey, array|object $payload, array $headers = []): void
    {
        $this->engine->dispatch(new Message(HandlerKind::Event, $routingKey, $payload, $headers));
    }
}
