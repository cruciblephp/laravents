<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Laravents\Invocation\Type;
use ReflectionParameter;

/**
 * Headers travel as strings. For an int, float or bool parameter a value that converts without
 * loss is converted; anything else is passed as is, so PHP's own type check reports it.
 */
final class HeaderValue
{
    /**
     * A header or Context value as the string the metadata keys and the outbox columns hold: a string as it is, an
     * int as its digits, anything else (null, a float, a bool) as null. The one coercion the metadata pipe, Context
     * and the outbox share.
     */
    public static function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    public static function for(ReflectionParameter $parameter, int|float|string|bool $value): int|float|string|bool
    {
        $names = Type::of($parameter)->names();

        // PHP widens an int to float itself, strict types or not; a union is left to PHP.
        if (count($names) !== 1 || ! is_string($value)) {
            return $value;
        }

        return match ($names[0]) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => match (strtolower($value)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => $value,
            },
            default => $value,
        };
    }
}
