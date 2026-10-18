<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Http\Desk\Handlers;

use Illuminate\Http\Resources\Json\JsonResource;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Expose;
use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\Http\Desk\Api\ArchiveTicket;
use Laravents\Tests\Fixtures\Http\Desk\Api\GetReceipt;
use Laravents\Tests\Fixtures\Http\Desk\Api\Receipt;
use Laravents\Tests\Fixtures\Http\Desk\Api\ShowTicket;
use Laravents\Tests\Fixtures\Recorder;

final class DeskHandlers
{
    #[CommandHandler]
    #[Expose]
    public function archive(ArchiveTicket $command): void
    {
        Recorder::record('archive', $command->id);
    }

    #[QueryHandler]
    #[Expose]
    public function receipt(GetReceipt $query): Receipt
    {
        return new Receipt($query->id);
    }

    #[QueryHandler]
    #[Expose]
    public function show(ShowTicket $query): JsonResource
    {
        return JsonResource::make(['id' => $query->id])->additional(['meta' => ['source' => 'desk']]);
    }
}
