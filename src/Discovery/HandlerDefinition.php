<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use LucianoPereira\Crucible\Attributes\Check;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

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
        return array_map(static fn (ReflectionAttribute $found): object => $found->newInstance(), $this->reflectedAttributes($attribute));
    }

    /**
     * The attribute attribute() answers, as its class and the arguments written in the source, when every argument
     * is plain data (a string, number, bool, null, or an array of them): `new $class(...$arguments)` builds it, as
     * reflection does. Null when the handler carries none, false when an argument is anything else (an enum, an
     * object), which only reflection rebuilds.
     *
     * @param  class-string  $attribute
     * @return array{class-string, array<array-key, mixed>}|false|null
     */
    public function plainAttribute(string $attribute): array|false|null
    {
        $found = $this->reflectedAttributes($attribute)[0] ?? null;

        if ($found === null) {
            return null;
        }

        $arguments = $found->getArguments();

        return self::plain($arguments) ? [$found->getName(), $arguments] : false;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return list<ReflectionAttribute<T>>
     */
    private function reflectedAttributes(string $attribute): array
    {
        return [
            ...(new ReflectionMethod($this->class, $this->method))->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF),
            ...(new ReflectionClass($this->class))->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF),
        ];
    }

    #[Check([null], returns: true, name: 'null')]
    #[Check(['max'], returns: true, name: 'a string')]
    #[Check([3], returns: true, name: 'an int')]
    #[Check([1.5], returns: true, name: 'a float')]
    #[Check([false], returns: true, name: 'a bool')]
    #[Check([['max' => 3, 'tags' => ['a', null]]], returns: true, name: 'nested arrays of them')]
    #[Check([HandlerKind::Command], returns: false, name: 'an enum, which var_export() writes as a runtime constant')]
    #[Check([new stdClass], returns: false, name: 'an object')]
    #[Check([['ok', [HandlerKind::Event]]], returns: false, name: 'an enum deep in an array')]
    private static function plain(mixed $value): bool
    {
        if (! is_array($value)) {
            return $value === null || is_scalar($value);
        }

        foreach ($value as $item) {
            if (! self::plain($item)) {
                return false;
            }
        }

        return true;
    }
}
