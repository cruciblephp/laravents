<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\QueryHandler;

final class DuplicateQueryKey
{
    /** @param array<string, mixed> $payload */
    #[QueryHandler('shop.find_order')]
    public function again(array $payload): int
    {
        return 0;
    }
}
