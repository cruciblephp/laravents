<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Laravents\Contracts\CommandBus as CommandBusContract;
use Laravents\Discovery\HandlerKind;

final readonly class CommandBus extends MessageBus implements CommandBusContract
{
    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function send(string $routingKey, array|object $payload = [], array $headers = []): mixed
    {
        return $this->route($routingKey, $payload, $headers);
    }

    /** @param array<string, scalar|null> $headers */
    public function dispatch(object $command, array $headers = []): mixed
    {
        return $this->routeObject($command, $headers);
    }

    protected function kind(): HandlerKind
    {
        return HandlerKind::Command;
    }
}
