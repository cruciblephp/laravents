<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Laravents\Contracts\QueryBus as QueryBusContract;
use Laravents\Discovery\HandlerKind;

final readonly class QueryBus extends MessageBus implements QueryBusContract
{
    protected const HandlerKind KIND = HandlerKind::Query;

    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function ask(string $routingKey, array|object $payload = [], array $headers = []): mixed
    {
        return $this->route($routingKey, $payload, $headers);
    }

    /** @param array<string, scalar|null> $headers */
    public function dispatch(object $query, array $headers = []): mixed
    {
        return $this->routeObject($query, $headers);
    }
}
