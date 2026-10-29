<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravents\Attributes\Publishes;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Invocation\Type;
use ReflectionClass;

/**
 * Reads #[Publishes] from a class and checks, at discovery, that each event can be built from the model, so a
 * mistake fails at boot or laravents:cache rather than on the first save.
 */
final class ModelPublications
{
    /**
     * @param  ReflectionClass<object>  $class
     * @return list<ModelPublication>
     */
    public static function read(ReflectionClass $class): array
    {
        $attributes = $class->getAttributes(Publishes::class);

        if ($attributes === []) {
            return [];
        }

        $location = $class->getName().' ('.$class->getFileName().':'.$class->getStartLine().')';

        if (! $class->isSubclassOf(Model::class) || $class->isAbstract()) {
            throw DiscoveryException::invalid($location, 'has #[Publishes], but #[Publishes] is only allowed on a concrete Eloquent model');
        }

        /** @var Model $model */
        $model = $class->newInstance();
        $publications = [];

        foreach ($attributes as $attribute) {
            $publishes = $attribute->newInstance();

            if (! in_array($publishes->on, $model->getObservableEvents(), true)) {
                throw DiscoveryException::invalid($location, sprintf(
                    'publishes [%s] on [%s], which is not one of its model events (%s)',
                    $publishes->event,
                    $publishes->on,
                    implode(', ', $model->getObservableEvents()),
                ));
            }

            if (! class_exists($publishes->event) || ! (new ReflectionClass($publishes->event))->isInstantiable()) {
                throw DiscoveryException::invalid($location, sprintf('publishes [%s], which is not an instantiable class', $publishes->event));
            }

            $publications[] = new ModelPublication(
                model: $model::class,
                hook: $publishes->on,
                event: $publishes->event,
                fromModel: self::checkBuildable($location, $model, $publishes->event),
                file: (string) $class->getFileName(),
                line: (int) $class->getStartLine(),
            );
        }

        return $publications;
    }

    /**
     * True when the event has fromModel(), false when its constructor is filled from the model's attributes.
     *
     * @param  class-string  $event
     */
    private static function checkBuildable(string $location, Model $model, string $event): bool
    {
        $reflection = new ReflectionClass($event);

        if ($reflection->hasMethod('fromModel')) {
            $method = $reflection->getMethod('fromModel');
            $named = Type::of($method->getParameters()[0] ?? null)->named();

            if (! $method->isPublic() || ! $method->isStatic() || $method->getNumberOfRequiredParameters() !== 1
                || $named === null || ! $model instanceof $named) {
                throw DiscoveryException::invalid($location, sprintf(
                    'publishes [%s], whose fromModel() must be public static and take the model as its one parameter',
                    $event,
                ));
            }

            return true;
        }

        $known = self::attributes($model);

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isOptional() && Type::of($parameter)->named() === null) {
                continue;
            }

            if (! in_array($parameter->getName(), $known, true) && ! in_array(Str::snake($parameter->getName()), $known, true)) {
                throw DiscoveryException::invalid($location, sprintf(
                    'publishes [%s], which cannot be built from it: constructor parameter [$%s] names no attribute the model declares (%s) and the event has no fromModel()',
                    $event,
                    $parameter->getName(),
                    implode(', ', $known),
                ));
            }
        }

        return false;
    }

    /**
     * The attributes a model declares without asking the database.
     *
     * @return list<string>
     */
    private static function attributes(Model $model): array
    {
        $names = [$model->getKeyName(), ...$model->getFillable(), ...array_keys($model->getCasts()), ...array_keys($model->getAttributes())];

        if ($model->usesTimestamps()) {
            $names[] = $model->getCreatedAtColumn();
            $names[] = $model->getUpdatedAtColumn();
        }

        if (method_exists($model, 'getDeletedAtColumn')) {
            $names[] = $model->getDeletedAtColumn();
        }

        return array_values(array_unique(array_filter($names, is_string(...))));
    }
}
