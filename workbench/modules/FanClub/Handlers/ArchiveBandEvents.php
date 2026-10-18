<?php

declare(strict_types=1);

namespace Modules\FanClub\Handlers;

use Illuminate\Support\Facades\Log;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;

final class ArchiveBandEvents
{
    /**
     * Every Band event, one log line each: keyed (band.split) or published as an object (NicknameGiven is
     * band.nickname_given). A pattern covers events of any shape, so the payload comes as published, and the Api's
     * classes are not named.
     *
     * @param  object|array<string, mixed>  $event
     */
    #[EventHandler('band.*')]
    public function __invoke(object|array $event, #[Header('message_id')] string $messageId): void
    {
        Log::info('Band event', ['event' => is_object($event) ? $event::class : $event, 'message_id' => $messageId]);
    }
}
