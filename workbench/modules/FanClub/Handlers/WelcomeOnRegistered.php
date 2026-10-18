<?php

declare(strict_types=1);

namespace Modules\FanClub\Handlers;

use Illuminate\Auth\Events\Registered;
use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Facades\Laravents;
use LogicException;
use Modules\FanClub\Api\WelcomeFan;

final class WelcomeOnRegistered
{
    /**
     * Laravel's own Registered event, handed in by its dispatcher: event(new Registered($user)) is enough. Runs on
     * the queue, and the publication id goes along as the idempotency key.
     */
    #[EventHandler]
    #[Asynchronous('fan_club')]
    public function __invoke(Registered $event, #[Header('publication_id')] string $publicationId): void
    {
        $userId = $event->user->getAuthIdentifier();

        // A framework event has no actor of its own (a starter kit fires Registered for a guest), so say who this is for.
        Laravents::as($event->user)->command(new WelcomeFan(
            userId: is_numeric($userId) ? (int) $userId : throw new LogicException('Fans are users with integer ids.'),
            publicationId: $publicationId,
        ));
    }
}
