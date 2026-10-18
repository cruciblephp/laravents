<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Bus\Converter;

use DateTimeImmutable;

/** A required scalar, a class the container builds, and an optional scalar: only the first can be missing. */
final readonly class BookGig
{
    public function __construct(public int $venueId, public DateTimeImmutable $bookedAt, public ?string $note = null) {}
}
