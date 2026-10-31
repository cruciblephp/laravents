<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Bus\Converter;

use Illuminate\Contracts\Container\SelfBuilding;

/** A message that builds itself: the container calls newInstance() in place of the constructor. */
final readonly class SelfBuiltGig implements SelfBuilding
{
    public function __construct(public string $venue) {}

    public static function newInstance(): self
    {
        return new self('built by itself');
    }
}
