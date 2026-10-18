<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Http\Kiosk\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Expose;
use Laravents\Attributes\Header;
use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\Http\Kiosk\Api\FindStamp;
use Laravents\Tests\Fixtures\Http\Kiosk\Api\ShredTicket;
use Laravents\Tests\Fixtures\Http\Kiosk\Api\StampTicket;
use Laravents\Tests\Fixtures\Recorder;

/**
 * Called through ExposedDispatcher as an adapter would: each handler returns what it saw, so the test reads the
 * raw result and the correlation_id the adapter passed.
 */
final class KioskHandlers
{
    /** @return array{label: string, correlation_id: string} */
    #[CommandHandler]
    #[Expose]
    public function stamp(StampTicket $command, #[Header('correlation_id')] string $correlationId): array
    {
        return ['label' => $command->label, 'correlation_id' => $correlationId];
    }

    #[QueryHandler]
    #[Expose]
    public function find(FindStamp $query, #[Header('correlation_id')] string $correlationId): \stdClass
    {
        return (object) ['id' => $query->id, 'correlation_id' => $correlationId];
    }

    #[CommandHandler]
    public function shred(ShredTicket $command): void
    {
        Recorder::record('shred', $command->id);
    }
}
