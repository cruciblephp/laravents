<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Shop\Api;

final readonly class DeclineOrder
{
    public function __construct(public string $sku) {}
}
