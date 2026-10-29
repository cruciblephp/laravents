<?php

declare(strict_types=1);

namespace Laravents\Invocation;

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Stringable;

/**
 * A declared type, read once: the names it is made of, the class it names, and whether it takes a value. The one place
 * Laravents reads a parameter's or a return's type, so discovery, the payload converter, headers and manifests agree.
 * Plain reflection, nothing else.
 *
 * @internal
 */
final readonly class Type implements Stringable
{
    /** @param list<ReflectionNamedType> $members  The named types, one for a named type, each of a union's */
    private function __construct(
        private ?ReflectionType $type,
        private array $members,
    ) {}

    public static function of(ReflectionParameter|ReflectionType|null $type): self
    {
        $type = $type instanceof ReflectionParameter ? $type->getType() : $type;

        $members = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => array_values(array_filter($type->getTypes(), static fn (ReflectionType $member): bool => $member instanceof ReflectionNamedType)),
            default => [],
        };

        return new self($type, $members);
    }

    /**
     * Every named type the declaration is made of, builtins included, in the order PHP reports them: ['int'] for
     * ?int, ['Foo', 'null'] for Foo|null written out. None for no type and for an intersection.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ReflectionNamedType $member): string => $member->getName(), $this->members);
    }

    /** The class or interface name, when the type is one named type that is not a builtin (?Foo included); else null. */
    public function named(): ?string
    {
        return $this->type instanceof ReflectionNamedType && ! $this->type->isBuiltin() ? $this->type->getName() : null;
    }

    /**
     * named(), when that class or interface exists.
     *
     * @return class-string|null
     */
    public function class(): ?string
    {
        $name = $this->named();

        return $name !== null && (class_exists($name) || interface_exists($name)) ? $name : null;
    }

    /**
     * Whether the value can be passed for this type: as strict_types holds it, or, not strict, as PHP's coercive mode
     * does, which is how the container calls a constructor (a numeric string for an int, any scalar for a bool). No
     * type, and mixed, take anything; an intersection is left to PHP.
     */
    public function accepts(mixed $value, bool $strict = true): bool
    {
        if (! $this->type instanceof ReflectionType || $this->type instanceof ReflectionIntersectionType) {
            return true;
        }

        if ($value === null && $this->type->allowsNull()) {
            return true;
        }

        foreach ($this->members as $member) {
            if ($this->takes($member, $value, $strict)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return (string) $this->type;
    }

    private function takes(ReflectionNamedType $member, mixed $value, bool $strict): bool
    {
        $name = $member->getName();

        if (! $member->isBuiltin()) {
            return $value instanceof $name;
        }

        $scalar = is_int($value) || is_float($value) || is_string($value) || is_bool($value);

        return match ($name) {
            'mixed' => true,
            'null' => $value === null,
            'int' => is_int($value) || ! $strict && (is_bool($value) || is_float($value) && is_finite($value) || is_string($value) && is_numeric($value)),
            'float' => is_float($value) || is_int($value) || ! $strict && (is_bool($value) || is_string($value) && is_numeric($value)),
            'string' => is_string($value) || ! $strict && ($scalar || $value instanceof Stringable),
            'bool' => is_bool($value) || ! $strict && $scalar,
            'false' => $value === false,
            'true' => $value === true,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            default => false,
        };
    }
}
