<?php

declare(strict_types=1);

namespace Laravents\Invocation;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Container\SelfBuilding;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Builds a message as Container::makeWith($class, $payload) does, without the container, when nothing makeWith()
 * consults could change the object: every constructor parameter is in the payload (so none is injected, defaulted or
 * read from an attribute), the class is not bound, aliased, attributed or SelfBuilding, and the container holds no
 * resolving callback, extender or contextual binding that applies to it. Otherwise null, and the caller asks the
 * container.
 *
 * The constructor is called through reflection, as the container calls it from a file without strict_types: a field
 * is coerced to its parameter's type the same way ('7' to 7).
 */
final class Construction
{
    /**
     * Each class's constructor parameter names, read once per process; false for a class only the container builds.
     *
     * @var array<class-string, array{ReflectionClass<object>, list<string>}|false>
     */
    private static array $plans = [];

    /** @var (Closure(Container, class-string): bool)|null */
    private static ?Closure $hooked = null;

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $payload
     */
    public static function build(ContainerContract $container, string $class, array $payload): ?object
    {
        $plan = self::$plans[$class] ??= self::plan($class);

        if ($plan === false || ! $container instanceof Container || $container->bound($class)) {
            return null;
        }

        $arguments = [];

        foreach ($plan[1] as $name) {
            if (! array_key_exists($name, $payload)) {
                return null;
            }

            $arguments[] = $payload[$name];
        }

        if ((self::$hooked ??= self::hooked())($container, $class)) {
            return null;
        }

        return $plan[0]->newInstanceArgs($arguments);
    }

    /**
     * @param  class-string  $class
     * @return array{ReflectionClass<object>, list<string>}|false
     */
    private static function plan(string $class): array|false
    {
        if (! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        // No parameters: makeWith() with an empty payload is a plain make(), which marks the class resolved.
        if (! $constructor instanceof ReflectionMethod || $constructor->getNumberOfParameters() === 0 || ! $reflection->isInstantiable()
            || $reflection->getAttributes() !== [] || is_a($class, SelfBuilding::class, true)) {
            return false;
        }

        return [$reflection, array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters())];
    }

    /**
     * Whether the container would run anything of its own while building $class: a before-, after- or resolving
     * callback (global, or for the class or a parent or interface of it), an extender, or a contextual binding, by
     * the class or an alias of it. Laravel keeps these protected, so they are read from the container's own scope;
     * a Laravel without one of them answers true, and every message is built by the container.
     *
     * @return Closure(Container, class-string): bool
     */
    private static function hooked(): Closure
    {
        foreach (['globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks', 'extenders', 'buildStack', 'abstractAliases', 'contextual'] as $property) {
            if (! property_exists(Container::class, $property)) {
                return static fn (): bool => true;
            }
        }

        return Closure::bind(static function (Container $container, string $class): bool {
            if ($container->globalBeforeResolvingCallbacks !== [] || $container->globalResolvingCallbacks !== [] || $container->globalAfterResolvingCallbacks !== []
                || isset($container->extenders[$class]) || isset($container->abstractAliases[$class])) {
                return true;
            }

            foreach ([$container->beforeResolvingCallbacks, $container->resolvingCallbacks, $container->afterResolvingCallbacks] as $callbacks) {
                foreach (array_keys($callbacks) as $type) {
                    if (is_a($class, $type, true)) {
                        return true;
                    }
                }
            }

            // makeWith() first asks for a contextual binding in the context of what is being built now.
            $building = $container->buildStack === [] ? false : $container->buildStack[array_key_last($container->buildStack)];

            return isset($container->contextual[$building][$class]);
        }, null, Container::class);
    }
}
