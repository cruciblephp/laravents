<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Canvas\Gig\Api;

use Laravents\Concerns\Publishable;

/** A query's result that is also published: Publishable makes it an event. */
final readonly class GigView
{
    use Publishable;

    public function __construct(public int $id) {}
}
