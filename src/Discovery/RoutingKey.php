<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Support\Str;

/**
 * Routing keys by convention: Modules\Band\Api\GiveNickname becomes band.give_nickname.
 */
final class RoutingKey
{
    /**
     * The namespace segment directly under the root, or null when the class is outside it.
     */
    public static function module(string $class, string $rootNamespace): ?string
    {
        $prefix = trim($rootNamespace, '\\').'\\';

        if (! str_starts_with($class, $prefix)) {
            return null;
        }

        $segments = explode('\\', substr($class, strlen($prefix)));

        return count($segments) > 1 ? $segments[0] : null;
    }

    /**
     * The module and the class name in snake case, with the module's words removed from the class name once.
     */
    public static function derive(string $class, string $rootNamespace): ?string
    {
        $module = self::module($class, $rootNamespace);

        if ($module === null) {
            return null;
        }

        $moduleWords = explode('_', Str::snake($module));
        $classWords = explode('_', Str::snake(class_basename($class)));
        $action = self::withoutSequence($classWords, $moduleWords) ?: $classWords;

        return Str::snake($module).'.'.implode('_', $action);
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $sequence
     * @return list<string>
     */
    private static function withoutSequence(array $words, array $sequence): array
    {
        $length = count($sequence);
        $counter = count($words);

        for ($i = 0; $i + $length <= $counter; $i++) {
            if (array_slice($words, $i, $length) === $sequence) {
                return [...array_slice($words, 0, $i), ...array_slice($words, $i + $length)];
            }
        }

        return $words;
    }
}
