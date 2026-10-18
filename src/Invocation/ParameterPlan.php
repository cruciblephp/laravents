<?php

declare(strict_types=1);

namespace Laravents\Invocation;

use Laravents\Attributes\Header;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * What Arguments needs to know about one parameter of a handler or interceptor method, read once per process.
 */
final readonly class ParameterPlan
{
    /**
     * @param  string|null  $header  The #[Header] name, when the parameter has one
     * @param  class-string|null  $class  The parameter's class or interface, when its type is one named type of that kind
     */
    public function __construct(
        public string $name,
        public ?string $header,
        public ?string $class,
        public bool $nullable,
        public bool $hasDefault,
        public ReflectionParameter $reflection,
    ) {}

    public static function of(ReflectionParameter $parameter): self
    {
        $type = $parameter->getType();
        // A built-in type (int, array, ...) is neither a class nor an interface, so the check below leaves it out.
        $class = $type instanceof ReflectionNamedType ? $type->getName() : null;

        return new self(
            name: $parameter->getName(),
            header: ($parameter->getAttributes(Header::class)[0] ?? null)?->newInstance()->name,
            class: $class !== null && (class_exists($class) || interface_exists($class)) ? $class : null,
            nullable: $parameter->allowsNull(),
            hasDefault: $parameter->isDefaultValueAvailable(),
            reflection: $parameter,
        );
    }
}
