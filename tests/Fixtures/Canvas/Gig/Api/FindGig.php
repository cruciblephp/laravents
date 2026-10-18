<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Canvas\Gig\Api;

use Laravents\Concerns\Askable;

final readonly class FindGig
{
    use Askable;

    public function __construct(public int $id) {}
}
