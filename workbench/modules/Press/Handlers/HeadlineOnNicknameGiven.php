<?php

declare(strict_types=1);

namespace Modules\Press\Handlers;

use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Contracts\CommandBus;
use Modules\Band\Api\NicknameGiven;
use Modules\Press\Api\PrintHeadline;

final class HeadlineOnNicknameGiven
{
    /**
     * Runs on the queue as the member who gave the nickname. Delivery is at least once, so the publication id
     * goes along as the idempotency key: a redelivered publication prints once.
     */
    #[EventHandler]
    #[Asynchronous('press')]
    public function __invoke(NicknameGiven $event, CommandBus $commands, #[Header('publication_id')] string $publicationId): void
    {
        $commands->dispatch(new PrintHeadline($event->nicknameId, $event->memberId, $publicationId));
    }
}
