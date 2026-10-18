<?php

declare(strict_types=1);

namespace Laravents\Discovery;

/**
 * A routing-key pattern on #[EventHandler]: dotted segments where `*` stands for exactly one whole segment, as an
 * AMQP topic binding reads it and as Ecotone subscribes to named events. Nothing else is an operator: no `#`, no
 * `*` inside a segment, no expression. Compiled once at discovery to one regular expression the definition carries,
 * so the registry (the one place that matches keys) never compiles at run time.
 */
final class KeyPattern
{
    /** Characters that would be an operator somewhere; only `*` as a whole segment is one here. */
    private const string OPERATORS = '#?+[]{}()|^$';

    /** Whether a routing key on #[EventHandler] is meant as a pattern: it holds `*` or another operator character. */
    public static function isPattern(string $routingKey): bool
    {
        return strpbrk($routingKey, '*'.self::OPERATORS) !== false;
    }

    /**
     * Why the pattern is refused, or null when it is valid.
     */
    public static function refusal(string $pattern): ?string
    {
        $operator = strpbrk($pattern, self::OPERATORS);

        if ($operator !== false) {
            return sprintf('has the routing-key pattern [%s] with [%s], but a pattern knows one operator: * standing for one whole dotted segment', $pattern, $operator[0]);
        }

        $segments = explode('.', $pattern);

        if (count($segments) < 2) {
            return sprintf('has the routing-key pattern [%s] with fewer than two segments, but a pattern names at least one literal segment and one *, as in chat.*', $pattern);
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                return sprintf('has the routing-key pattern [%s] with an empty segment', $pattern);
            }

            if ($segment !== '*' && str_contains($segment, '*')) {
                return sprintf('has the routing-key pattern [%s] with * inside the segment [%s], but * stands for one whole dotted segment', $pattern, $segment);
            }
        }

        if (array_diff($segments, ['*']) === []) {
            return sprintf('has the routing-key pattern [%s] with no literal segment, but a pattern names at least one, as in chat.* or *.opened', $pattern);
        }

        return null;
    }

    /** The pattern as one anchored regular expression: `*` is one segment of anything but a dot. */
    public static function compile(string $pattern): string
    {
        return '/^'.implode('\.', array_map(
            static fn (string $segment): string => $segment === '*' ? '[^.]+' : preg_quote($segment, '/'),
            explode('.', $pattern),
        )).'$/';
    }

    /** Whether a routing key matches a compiled pattern. */
    public static function matches(string $compiled, string $routingKey): bool
    {
        return preg_match($compiled, $routingKey) === 1;
    }
}
