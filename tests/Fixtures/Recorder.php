<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures;

/**
 * Fixture handlers record what reached them, in order.
 */
final class Recorder
{
    /** @var list<array{string, mixed}> */
    private static array $calls = [];

    public static function record(string $handler, mixed $payload = null): void
    {
        self::$calls[] = [$handler, $payload];
    }

    public static function reset(): void
    {
        self::$calls = [];
    }

    /** @return list<array{string, mixed}> */
    public static function calls(): array
    {
        return self::$calls;
    }

    /** @return list<string> */
    public static function handlers(): array
    {
        return array_map(static fn (array $call): string => $call[0], self::$calls);
    }
}
