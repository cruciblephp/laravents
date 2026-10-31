<?php

declare(strict_types=1);

namespace Laravents\Invocation;

use Laravents\Bus\HeaderValue;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Exceptions\MissingHeaderException;
use ReflectionMethod;

/**
 * Named arguments for a handler or interceptor method; whatever is left the container resolves.
 */
final class Arguments
{
    /**
     * Each method's parameter plan, read once per process and keyed by Class::method. Nothing clears it: code does
     * not change under a running process, which the registry cache assumes as well.
     *
     * @var array<string, list<ParameterPlan>>
     */
    private static array $plans = [];

    /**
     * Each method's reflection when a call naming all its parameters can skip the container's call, else false.
     *
     * @var array<string, ReflectionMethod|false>
     */
    private static array $direct = [];

    /**
     * @param  class-string  $class
     * @param  list<object>  $byType  Given to the first parameter whose class each one is an instance of
     * @param  array<string, mixed>  $byName
     * @return array<string, mixed>
     */
    public static function for(string $class, string $method, Message $message, HandlerDefinition $handler, array $byType = [], array $byName = [], int $skip = 0): array
    {
        $arguments = [];

        foreach (array_slice(self::plan($class, $method), $skip) as $parameter) {
            $name = $parameter->name;

            if (array_key_exists($name, $byName)) {
                $arguments[$name] = $byName[$name];

                continue;
            }

            if ($parameter->header !== null) {
                $value = $message->headers[$parameter->header] ?? null;

                if ($value !== null) {
                    $arguments[$name] = HeaderValue::for($parameter->reflection, $value);
                } elseif (! $parameter->hasDefault) {
                    $arguments[$name] = $parameter->nullable
                        ? null
                        : throw MissingHeaderException::for($parameter->header, $handler);
                }

                continue;
            }

            if ($parameter->class !== null) {
                foreach ($byType as $candidate) {
                    if ($candidate instanceof $parameter->class) {
                        $arguments[$name] = $candidate;

                        break;
                    }
                }
            }
        }

        return $arguments;
    }

    /**
     * The method's parameters as Arguments reads them, reflected on first use only.
     *
     * @param  class-string  $class
     * @return list<ParameterPlan>
     */
    public static function plan(string $class, string $method): array
    {
        return self::$plans[$class.'::'.$method] ??= array_map(
            ParameterPlan::of(...),
            (new ReflectionMethod($class, $method))->getParameters(),
        );
    }

    /**
     * The method, when a call that names every parameter needs nothing more from the container's call: no parameter
     * carries an attribute (the container runs its attribute callbacks for each) or is variadic (the container
     * passes a named value to it whole). Else null.
     *
     * @param  class-string  $class
     */
    public static function direct(string $class, string $method): ?ReflectionMethod
    {
        $direct = self::$direct[$class.'::'.$method] ??= (static function () use ($class, $method): ReflectionMethod|false {
            foreach (self::plan($class, $method) as $parameter) {
                if ($parameter->reflection->isVariadic() || $parameter->reflection->getAttributes() !== []) {
                    return false;
                }
            }

            return new ReflectionMethod($class, $method);
        })();

        return $direct === false ? null : $direct;
    }
}
