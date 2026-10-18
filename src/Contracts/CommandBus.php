<?php

declare(strict_types=1);

namespace Laravents\Contracts;

/**
 * Sends a command to its one handler. Depend on this, not on Laravents\Bus\CommandBus, so a fake can stand in,
 * as Illuminate\Contracts\Bus\Dispatcher does for Bus::fake().
 */
interface CommandBus
{
    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function send(string $routingKey, array|object $payload = [], array $headers = []): mixed;

    /** @param array<string, scalar|null> $headers */
    public function dispatch(object $command, array $headers = []): mixed;
}
