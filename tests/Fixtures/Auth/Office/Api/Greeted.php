<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Auth\Office\Api;

final readonly class Greeted
{
    public function __construct(public int $times = 1) {}
}
