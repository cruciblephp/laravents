<?php

declare(strict_types=1);

namespace Modules\FanClub\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Header;
use Laravents\Attributes\Transactional;
use Modules\FanClub\Api\WelcomeFan;
use Modules\FanClub\Models\Welcome;

final class FanClubHandlers
{
    #[CommandHandler]
    #[Authorize]
    #[Transactional]
    public function welcome(
        WelcomeFan $command,
        #[CurrentUser] Authenticatable $actor,
        #[Header('correlation_id')] string $correlationId,
    ): int {
        return Welcome::query()->firstOrCreate(['publication_id' => $command->publicationId], [
            'user_id' => $command->userId,
            'sent_by' => $actor->getAuthIdentifier(),
            'correlation_id' => $correlationId,
        ])->id;
    }
}
