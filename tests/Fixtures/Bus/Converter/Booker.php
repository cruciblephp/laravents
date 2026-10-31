<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Bus\Converter;

use Laravents\Bus\PayloadConverter;

/** Converts a payload while the container calls it, so the container is building in this class's context. */
final readonly class Booker
{
    public function book(PayloadConverter $converter): object
    {
        return $converter->convert(Gig::class, ['venue' => 'The Cavern']);
    }
}
