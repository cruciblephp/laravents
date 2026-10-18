<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\ClassDiscovery;

use Laravents\Tests\Fixtures\ClassDiscovery\Band\Tunes;

/** A class that uses a trait, an anonymous class and ::class, none of which declares one. */
final class Concert
{
    use Tunes;

    public function encore(): object
    {
        return new class {};
    }

    public function name(): string
    {
        return self::class;
    }
}
