<?php

declare(strict_types=1);

namespace Laravents\Exceptions;

use InvalidArgumentException;
use Laravents\Discovery\HandlerKind;

final class UnknownRoutingKeyException extends InvalidArgumentException
{
    /** How many alternatives the message names at most. */
    private const int SUGGESTIONS = 3;

    /** Symfony's penalty for a part that does not match; an alternative scoring 2x this or more is dropped. */
    private const float THRESHOLD = 1e3;

    /** @param list<string> $knownKeys The registered keys of the same kind, the only ones worth suggesting */
    public static function forKey(HandlerKind $kind, string $routingKey, array $knownKeys): self
    {
        $message = sprintf('Unknown %s routing key [%s].', $kind->value, $routingKey);
        $alternatives = array_map(static fn (string $key): string => sprintf('[%s]', $key), self::alternatives($routingKey, $knownKeys));

        return new self(match (count($alternatives)) {
            0 => $message,
            1 => sprintf('%s Did you mean %s?', $message, $alternatives[0]),
            default => sprintf('%s Did you mean one of these? %s', $message, implode(', ', $alternatives)),
        });
    }

    /** @param class-string $class */
    public static function forClass(HandlerKind $kind, string $class): self
    {
        return new self(sprintf('No %s handler for [%s].', $kind->value, $class));
    }

    /**
     * An object sent through the dispatch() that the command and query contracts share, by a caller that did
     * not say which (the fake, which implements both): the registry knows it as neither.
     *
     * @param  class-string  $class
     */
    public static function forCommandOrQuery(string $class): self
    {
        return new self(sprintf('No command or query handler for [%s].', $class));
    }

    /**
     * Symfony Console's Application::findAlternatives(), which Artisan uses for "Did you mean": each part
     * (split on "." here, ":" there) and then the whole name is close when its Levenshtein distance is at most
     * a third of its length or it contains the typed part; a part that is not close adds the threshold, and an
     * alternative at twice the threshold or more is dropped. Symfony sorts the survivors by name; these are
     * sorted by score, best first (ties by name), and cut to three.
     *
     * @param  list<string>  $collection
     * @return list<string>
     */
    private static function alternatives(string $name, array $collection): array
    {
        /** @var array<string, int|float> $alternatives */
        $alternatives = [];
        $collectionParts = [];

        foreach ($collection as $item) {
            $collectionParts[$item] = explode('.', $item);
        }

        foreach (explode('.', $name) as $i => $subname) {
            foreach ($collectionParts as $collectionName => $parts) {
                $collectionName = (string) $collectionName;
                $exists = isset($alternatives[$collectionName]);

                if (! isset($parts[$i])) {
                    if ($exists) {
                        $alternatives[$collectionName] += self::THRESHOLD;
                    }

                    continue;
                }

                $lev = levenshtein($subname, $parts[$i]);

                if ($lev <= strlen($subname) / 3 || ($subname !== '' && str_contains($parts[$i], $subname))) {
                    $alternatives[$collectionName] = $exists ? $alternatives[$collectionName] + $lev : $lev;
                } elseif ($exists) {
                    $alternatives[$collectionName] += self::THRESHOLD;
                }
            }
        }

        foreach ($collection as $item) {
            $lev = levenshtein($name, $item);

            if ($lev <= strlen($name) / 3 || str_contains($item, $name)) {
                $alternatives[$item] = isset($alternatives[$item]) ? $alternatives[$item] - $lev : $lev;
            }
        }

        $alternatives = array_filter($alternatives, static fn (float|int $lev): bool => $lev < 2 * self::THRESHOLD);
        uksort($alternatives, static fn (string $a, string $b): int => [$alternatives[$a], $a] <=> [$alternatives[$b], $b]);

        return array_slice(array_map(strval(...), array_keys($alternatives)), 0, self::SUGGESTIONS);
    }
}
