<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Metadata\Billing\Api;

final readonly class Tag
{
    public function __construct(public string $tenant) {}
}
