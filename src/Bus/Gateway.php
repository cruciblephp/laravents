<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Laravents\Auth\ActAs;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\EventBus;
use Laravents\Contracts\QueryBus;

/**
 * What the Laravents facade resolves to: one entry point over the three buses, by object or by key.
 * It holds the bus contracts, so whatever is bound to them (the real buses, or a fake) is what it reaches, and the
 * ActAs that its PendingMessage uses for as($user).
 */
final readonly class Gateway
{
    public function __construct(
        private CommandBus $commands,
        private QueryBus $queries,
        private EventBus $events,
        public ActAs $actAs,
    ) {}

    /** Start a message dispatched as this user, on the guard given or the default. See PendingMessage::as(). */
    public function as(Authenticatable $user, ?string $guard = null): PendingMessage
    {
        return (new PendingMessage($this))->as($user, $guard);
    }

    public function withHeader(string $name, int|float|string|bool|null $value): PendingMessage
    {
        return (new PendingMessage($this))->withHeader($name, $value);
    }

    /** @param array<string, scalar|null> $headers */
    public function withHeaders(array $headers): PendingMessage
    {
        return new PendingMessage($this, $headers);
    }

    /**
     * @param  array<string, mixed>|object  $payload  With a routing key only
     * @param  array<string, scalar|null>  $headers
     */
    public function command(string|object $command, array|object $payload = [], array $headers = []): mixed
    {
        return is_object($command)
            ? $this->commands->dispatch($this->alone($command, $payload), $headers)
            : $this->commands->send($command, $payload, $headers);
    }

    /**
     * @param  array<string, mixed>|object  $payload  With a routing key only
     * @param  array<string, scalar|null>  $headers
     */
    public function query(string|object $query, array|object $payload = [], array $headers = []): mixed
    {
        return is_object($query)
            ? $this->queries->dispatch($this->alone($query, $payload), $headers)
            : $this->queries->ask($query, $payload, $headers);
    }

    /**
     * @param  array<string, mixed>|object  $payload  With a routing key only
     * @param  array<string, scalar|null>  $headers
     */
    public function publish(string|object $event, array|object $payload = [], array $headers = []): void
    {
        if (is_object($event)) {
            $this->events->publish($this->alone($event, $payload), $headers);

            return;
        }

        $this->events->publishTo($event, $payload, $headers);
    }

    /**
     * A message object carries its own payload, so a second one is a mistake, most likely headers in the wrong place.
     *
     * @param  array<string, mixed>|object  $payload
     */
    private function alone(object $message, array|object $payload): object
    {
        if ($payload !== []) {
            throw new InvalidArgumentException(sprintf('%s is already the payload; pass headers as headers: [...].', $message::class));
        }

        return $message;
    }
}
