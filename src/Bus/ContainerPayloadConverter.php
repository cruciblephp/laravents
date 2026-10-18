<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
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

        try {
            // makeWith() calls the constructor: a missing parameter is a BindingResolutionException,
            // a mistyped one is PHP's own TypeError. Anything else is not about the payload.
            $object = $this->container->makeWith($class, $payload);
        } catch (Throwable $e) {
            throw match (true) {
                $e instanceof BindingResolutionException => ValidationException::withMessages(
                    $this->missing($class, $payload) ?? ['payload' => [sprintf('The payload cannot build %s.', class_basename($class))]],
                ),
                $e instanceof TypeError => ValidationException::withMessages($this->mistyped($class, $e) ?? ['payload' => [$e->getMessage()]]),
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
            $type = $parameter->getType();
            $builtin = ! $type instanceof ReflectionNamedType || $type->isBuiltin();

            if ($builtin && ! $parameter->isOptional() && ! array_key_exists($parameter->getName(), $payload)) {
                $errors[$parameter->getName()] = [sprintf('The %s field is required.', $parameter->getName())];
            }
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * The constructor argument PHP rejected, read from the TypeError PHP raised for it.
     *
     * @param  class-string  $class
     * @return array<string, list<string>>|null
     */
    private function mistyped(string $class, TypeError $error): ?array
    {
        $pattern = '/^'.preg_quote($class, '/').'::__construct\(\): Argument #\d+ \(\$(\w+)\) must be of type ([^,]+),/';

        if (preg_match($pattern, $error->getMessage(), $match) !== 1) {
            return null;
        }

        return [$match[1] => [sprintf('The %s field must be of type %s.', $match[1], $match[2])]];
    }
}
