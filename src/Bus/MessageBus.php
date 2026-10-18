<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Exceptions\UnknownRoutingKeyException;

/**
 * What CommandBus and QueryBus share: a message, by routing key or by object, to the one handler of their kind.
 */
abstract readonly class MessageBus
{
    public function __construct(
        private Engine $engine,
        private Registry $registry,
        private PayloadConverter $converter,
    ) {}

    abstract protected function kind(): HandlerKind;

    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    protected function route(string $routingKey, array|object $payload, array $headers): mixed
    {
        $kind = $this->kind();
        $handler = $this->registry->handlerFor($kind, $routingKey)
            ?? throw UnknownRoutingKeyException::forKey($kind, $routingKey, $this->registry->routingKeys($kind));

        if ($handler->messageClass !== null) {
            $payload = $this->converter->convert($handler->messageClass, $payload);
        }

        return $this->engine->dispatch(new Message($kind, $routingKey, $payload, $headers));
    }

    /** @param array<string, scalar|null> $headers */
    protected function routeObject(object $message, array $headers): mixed
    {
        $routingKey = $this->registry->routingKeyFor($message::class, $this->kind())
            ?? throw UnknownRoutingKeyException::forClass($this->kind(), $message::class);

        return $this->route($routingKey, $message, $headers);
    }
}
