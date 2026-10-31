<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Bus\Converter;

/** A plain message: every field in the payload, nothing for the container to inject. */
final readonly class Gig implements Ticketed
{
    public function __construct(public string $venue) {}
}
