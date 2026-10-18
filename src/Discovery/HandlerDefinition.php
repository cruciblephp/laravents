<?php

declare(strict_types=1);

namespace Laravents\Discovery;

/**
 * One discovered handler method. Plain data, so a registry can later be cached as PHP.
 */
final readonly class HandlerDefinition
{
    /**
     * @param  class-string|null  $messageClass  The first parameter's class or interface, when it has one
     * @param  class-string  $class
     * @param  HandlerMode  $mode  Sync, Deferred (#[Deferred]) or Async (#[Asynchronous])
     * @param  string|null  $queue  The queue of an Async handler, else null
     * @param  bool  $exposed  #[Expose]: reachable through the HTTP gateway
     * @param  string|null  $pattern  An event handler's routing-key pattern (#[EventHandler('band.*')]) compiled to one
     *                                regular expression at discovery, else null; $routingKey keeps the pattern as written
     */
    public function __construct(
        public HandlerKind $kind,
        public ?string $routingKey,
        public ?string $messageClass,
        public string $class,
        public string $method,
        public ?string $module,
        public HandlerMode $mode,
        public ?string $queue,
        public bool $exposed,
        public string $file,
        public int $line,
        public ?string $pattern = null,
    ) {}

    public function isAsynchronous(): bool
    {
        return $this->mode === HandlerMode::Async;
    }

    public function isDeferred(): bool
    {
        return $this->mode === HandlerMode::Deferred;
    }

    /** An event handler that matches every routing key its pattern covers, rather than one key or one type. */
    public function isPattern(): bool
    {
        return $this->pattern !== null;
    }

    public function handler(): string
    {
        return $this->class.'@'.$this->method;
    }

    public function location(): string
    {
        return $this->handler().' ('.$this->file.':'.$this->line.')';
    }
}
