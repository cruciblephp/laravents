<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravents\Bus\PayloadConverter;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Invocation\Type;
use ReflectionClass;
use ReflectionParameter;

/**
 * A module's manifest: its module.json, read from the module's directory under laravents.modules.path and hydrated
 * into the application's manifest class (laravents.modules.manifest) by the PayloadConverter that builds every
 * message, so the class's constructor is the schema. A field the class does not take, one of the wrong type, a
 * missing required one, a file that is not a JSON object, and, when laravents.modules.manifest_required is on, a
 * module with no file, each fail discovery naming the file. The JSON is the module's data in a format any tool reads;
 * PHP reads the hydrated object.
 */
final readonly class ManifestReader
{
    /** @param class-string $class */
    public function __construct(
        private string $class,
        private bool $required,
        private string $path,
        private PayloadConverter $converter,
    ) {}

    /**
     * The reader laravents.modules.manifest asks for, or null when it names no class: then no module has a manifest.
     *
     * @throws DiscoveryException
     */
    public static function fromConfig(Repository $config, Container $container): ?self
    {
        $class = $config->get('laravents.modules.manifest');

        if ($class === null) {
            return null;
        }

        if (! is_string($class) || ! class_exists($class)) {
            throw DiscoveryException::invalid('laravents.modules.manifest', 'must be null or the class each module.json is hydrated into');
        }

        $path = $config->get('laravents.modules.path');

        return new self(
            $class,
            (bool) $config->get('laravents.modules.manifest_required', false),
            is_string($path) ? $path : '',
            $container->make(PayloadConverter::class),
        );
    }

    /**
     * The module's manifest, or null when it has no module.json and none is required.
     *
     * @throws DiscoveryException
     */
    public function read(string $module): ?object
    {
        $file = rtrim($this->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'module.json';

        if (! is_file($file)) {
            if ($this->required) {
                throw DiscoveryException::invalid($file, 'is missing, and laravents.modules.manifest_required asks every module for one');
            }

            return null;
        }

        $json = file_get_contents($file);

        if ($json === false) {
            throw DiscoveryException::invalid($file, 'cannot be read');
        }

        try {
            $fields = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw DiscoveryException::invalid($file, 'is not valid JSON: '.$e->getMessage());
        }

        if (! is_array($fields) || array_is_list($fields) && $fields !== []) {
            throw DiscoveryException::invalid($file, 'must hold a JSON object');
        }

        $named = array_combine(array_map(strval(...), array_keys($fields)), $fields);
        $parameters = $this->parameters();
        $unknown = array_keys(array_diff_key($named, $parameters));

        if ($unknown !== []) {
            throw DiscoveryException::invalid($file, sprintf('has [%s], which %s does not take', implode(', ', $unknown), $this->class));
        }

        foreach ($named as $field => $value) {
            $type = Type::of($parameters[$field]);

            // JSON has one number type: a whole number written 1.0 is an int, as JSON Schema's `integer` reads it.
            if (is_float($value) && is_finite($value) && floor($value) === $value && ! $type->accepts($value) && $type->accepts((int) $value)) {
                $named[$field] = $value = (int) $value;
            }

            // Strict, as strict_types holds it: the container's call would coerce 2 into "2".
            if (! $type->accepts($value)) {
                throw DiscoveryException::invalid($file, sprintf('does not build %s: the %s field must be of type %s', $this->class, $field, $type));
            }
        }

        try {
            return $this->converter->convert($this->class, $named);
        } catch (ValidationException $e) {
            throw DiscoveryException::invalid($file, sprintf('does not build %s: %s', $this->class, rtrim(implode(' ', $e->validator->errors()->all()), '.')));
        }
    }

    /**
     * The manifest class's constructor parameters, by name: its fields.
     *
     * @return array<string, ReflectionParameter>
     */
    private function parameters(): array
    {
        $parameters = [];

        foreach ((new ReflectionClass($this->class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }
}
