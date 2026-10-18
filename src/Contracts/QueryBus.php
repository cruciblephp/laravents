<?php

declare(strict_types=1);

namespace Laravents\Contracts;

/**
 * Asks a query of its one handler. Depend on this, not on Laravents\Bus\QueryBus, so a fake can stand in.
 */
interface QueryBus
{
    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function ask(string $routingKey, array|object $payload = [], array $headers = []): mixed;

    /** @param array<string, scalar|null> $headers */
    public function dispatch(object $query, array $headers = []): mixed;
}
