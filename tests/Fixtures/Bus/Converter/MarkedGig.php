<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Bus\Converter;

/** A message with a class attribute: the container's attribute callbacks see it when it is built. */
#[GigMarker]
final readonly class MarkedGig
{
    public function __construct(public string $venue) {}
}
