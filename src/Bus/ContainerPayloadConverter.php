<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravents\Invocation\Construction;
use Laravents\Invocation\Type;
use ReflectionClass;
use Throwable;
use TypeError;

/**
 * Hydrates constructor-promoted DTOs by parameter name through the container.
 */
final readonly class ContainerPayloadConverter implements PayloadConverter
{
    public function __construct(private Container $container) {}

    public function convert(string $class, array|object $payload): object
    {
        if ($payload instanceof $class) {
            return $payload;
        }

        if (is_object($payload)) {
            throw new InvalidArgumentException(sprintf('Expected %s, got %s.', $class, $payload::class));
        }

        // A class that is not there is the caller's mistake, not the payload's: no 422. An interface may be bound.
        if (! class_exists($class) && ! interface_exists($class)) {
            throw new InvalidArgumentException(sprintf('There is no class or interface %s to build.', $class));
        }

        try {
            // makeWith() calls the constructor: a missing parameter is a BindingResolutionException,
            // a mistyped one is PHP's own TypeError. Anything else is not about the payload. Construction
            // builds the object as makeWith() would when nothing of the container's own takes part.
            $object = Construction::build($this->container, $class, $payload) ?? $this->container->makeWith($class, $payload);
        } catch (Throwable $e) {
            throw match (true) {
                $e instanceof BindingResolutionException => ValidationException::withMessages(
                    $this->missing($class, $payload) ?? ['payload' => [sprintf('The payload cannot build %s.', class_basename($class))]],
                ),
                $e instanceof TypeError => ValidationException::withMessages($this->mistyped($class, $payload) ?? ['payload' => [$e->getMessage()]]),
                default => $e,
            };
        }

        if (! $object instanceof $class) {
            throw new InvalidArgumentException(sprintf('The container built %s for %s.', get_debug_type($object), $class));
        }

        return $object;
    }

    /**
     * Required constructor parameters with a builtin type that the payload does not provide.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>|null
     */
    private function missing(string $class, array $payload): ?array
    {
        $errors = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $builtin = Type::of($parameter)->named() === null;

            if ($builtin && ! $parameter->isOptional() && ! array_key_exists($parameter->getName(), $payload)) {
                $errors[$parameter->getName()] = [sprintf('The %s field is required.', $parameter->getName())];
            }
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * The constructor argument PHP rejected: the first given field its parameter's type does not take, as the
     * container's call coerces (Type, not strict). Null when every field fits, so the TypeError was not about one.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>|null
     */
    private function mistyped(string $class, array $payload): ?array
    {
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $type = Type::of($parameter);

            if (array_key_exists($name, $payload) && ! $type->accepts($payload[$name], strict: false)) {
                return [$name => [sprintf('The %s field must be of type %s.', $name, $type)]];
            }
        }

        return null;
    }
}
