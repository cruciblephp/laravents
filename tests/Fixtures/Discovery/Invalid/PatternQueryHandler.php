<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\QueryHandler;

final class PatternQueryHandler
{
    /** @param array<string, mixed> $query */
    #[QueryHandler('shop.*')]
    public function handle(array $query): null
    {
        return null;
    }
}
