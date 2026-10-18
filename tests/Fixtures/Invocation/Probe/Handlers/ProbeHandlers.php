<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Invocation\Probe\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Header;

/**
 * Dispatched by the Arguments memoisation test only, so its parameter plan is built there and nowhere else.
 */
final class ProbeHandlers
{
    /** @param array{n: int} $payload */
    #[CommandHandler('probe.ping')]
    public function ping(array $payload, #[Header('correlation_id')] string|int $correlationId): int
    {
        return $payload['n'];
    }
}
