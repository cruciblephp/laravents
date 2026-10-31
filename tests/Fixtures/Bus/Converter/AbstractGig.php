<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Bus\Converter;

/** Not instantiable: the container refuses it as it refuses any abstract class. */
abstract readonly class AbstractGig
{
    public function __construct(public string $venue) {}
}
