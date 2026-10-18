<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Canvas\Gig\Api;

use Laravents\Concerns\Sendable;

/** A command message with no handler yet: still a message, never an event. */
final readonly class BookGig
{
    use Sendable;

    public function __construct(public int $venueId) {}
}
