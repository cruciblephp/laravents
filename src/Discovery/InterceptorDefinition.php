<?php

declare(strict_types=1);

namespace Laravents\Discovery;

/**
 * One discovered interceptor method. Plain data, like HandlerDefinition.
 */
final readonly class InterceptorDefinition
{
    /**
     * @param  class-string  $pointcut
     * @param  class-string  $class
     */
    public function __construct(
        public InterceptorKind $kind,
        public string $pointcut,
        public int $precedence,
        public string $class,
        public string $method,
        public string $file,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->class.'@'.$this->method.' ('.$this->file.':'.$this->line.')';
    }
}
