<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Laravents\Discovery\HandlerKind;

/**
 * The only thing that travels through the engine.
 */
final readonly class Message
{
    /**
     * @param  object|array<string, mixed>  $payload  An array only when the handler's first parameter is not a class
     * @param  array<string, scalar|null>  $headers
     */
    public function __construct(
        public HandlerKind $kind,
        public string $routingKey,
        public object|array $payload,
        public array $headers = [],
    ) {}

    /** @param array<string, scalar|null> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->kind, $this->routingKey, $this->payload, $headers);
    }
}
