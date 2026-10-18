<?php

declare(strict_types=1);

namespace Laravents\Testing;

use Laravents\Discovery\HandlerKind;

/**
 * One message the fake intercepted, with both names it answers to: the class and the routing key.
 */
final readonly class RecordedMessage
{
    /**
     * @param  array<string, mixed>|object  $message  Hydrated when the registry knows the class, else as sent
     * @param  array<string, scalar|null>  $headers  As the caller passed them; Laravents' own metadata is not added
     * @param  class-string|null  $class
     */
    public function __construct(
        public HandlerKind $kind,
        public array|object $message,
        public array $headers,
        public ?string $class,
        public ?string $routingKey,
    ) {}

    /** Whether a class name or a routing key names this message. */
    public function is(string $name): bool
    {
        return $name === $this->class || $name === $this->routingKey;
    }

    /** The class when there is one, else the routing key, for failure messages. */
    public function name(): string
    {
        return $this->class ?? (string) $this->routingKey;
    }
}
