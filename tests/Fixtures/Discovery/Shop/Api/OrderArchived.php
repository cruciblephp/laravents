<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Shop\Api;

/**
 * An event nobody listens to.
 */
final readonly class OrderArchived
{
    public function __construct(public int $orderId) {}
}
