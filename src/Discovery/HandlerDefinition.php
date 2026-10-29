<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

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

    /**
     * The handler's attribute of this class, or of a class that extends or implements it: the method's, else its
     * class's, as an interceptor's pointcut is matched. Null when neither carries one. For an attribute of the
     * application's own beside #[Expose] (a display text, what a write changes), read without reflection by the caller.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    public function attribute(string $attribute): ?object
    {
        return $this->attributes($attribute)[0] ?? null;
    }

    /**
     * Every attribute of this class, or of a class that extends or implements it, on the handler: the method's, then
     * its class's.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    public function attributes(string $attribute): array
    {
        $found = [
            ...(new ReflectionMethod($this->class, $this->method))->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF),
            ...(new ReflectionClass($this->class))->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF),
        ];

        return array_map(static fn (ReflectionAttribute $found): object => $found->newInstance(), $found);
    }
}
