<?php

declare(strict_types=1);

namespace Laravents\Contracts;

/**
 * Publishes an event to its handlers. Depend on this, not on Laravents\Bus\EventBus, so a fake can stand in.
 */
interface EventBus
{
    /**
     * Runs every handler whose parameter type the event is an instance of. Zero handlers is a no-op.
     *
     * @param  array<string, scalar|null>  $headers
     */
    public function publish(object $event, array $headers = []): void;

    /**
     * Runs the handlers registered for a routing key with #[EventHandler('key')].
     *
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function publishTo(string $routingKey, array|object $payload, array $headers = []): void;
}
