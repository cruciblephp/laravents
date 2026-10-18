<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Headers and an actor first, then the message, like Mail::to()->send() and Bus::chain()->dispatch():
 * Laravents::withHeaders(['correlation_id' => 'req-1'])->command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']), or
 * Laravents::as($event->user)->command(new WelcomeFan(...)).
 */
final readonly class PendingMessage
{
    /** @param array<string, scalar|null> $headers */
    public function __construct(
        private Gateway $gateway,
        private array $headers = [],
        private ?Authenticatable $actor = null,
        private ?string $guard = null,
    ) {}

    public function withHeader(string $name, int|float|string|bool|null $value): static
    {
        return new self($this->gateway, [...$this->headers, $name => $value], $this->actor, $this->guard);
    }

    /** @param array<string, scalar|null> $headers */
    public function withHeaders(array $headers): static
    {
        return new self($this->gateway, [...$this->headers, ...$headers], $this->actor, $this->guard);
    }

    /**
     * Dispatch as this user: for that one dispatch the user is on the guard (the default when null), as a queued
     * handler runs as its actor, and the message and everything dispatched inside it carry that actor. The guard
     * and Context are put back afterwards. For code that knows who it acts for when nobody is logged in, such as a
     * handler of a framework event fired for a guest.
     */
    public function as(Authenticatable $user, ?string $guard = null): static
    {
        return new self($this->gateway, $this->headers, $user, $guard);
    }

    /** @param array<string, mixed>|object $payload With a routing key only */
    public function command(string|object $command, array|object $payload = []): mixed
    {
        return $this->dispatch(fn (): mixed => $this->gateway->command($command, $payload, $this->headers));
    }

    /** @param array<string, mixed>|object $payload With a routing key only */
    public function query(string|object $query, array|object $payload = []): mixed
    {
        return $this->dispatch(fn (): mixed => $this->gateway->query($query, $payload, $this->headers));
    }

    /** @param array<string, mixed>|object $payload With a routing key only */
    public function publish(string|object $event, array|object $payload = []): void
    {
        $this->dispatch(function () use ($event, $payload): null {
            $this->gateway->publish($event, $payload, $this->headers);

            return null;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $dispatch
     * @return T
     */
    private function dispatch(Closure $dispatch): mixed
    {
        return $this->actor instanceof Authenticatable
            ? $this->gateway->actAs->run($this->actor, $this->guard, $dispatch)
            : $dispatch();
    }
}
