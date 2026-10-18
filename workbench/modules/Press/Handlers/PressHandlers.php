<?php

declare(strict_types=1);

namespace Modules\Press\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Header;
use Laravents\Attributes\Transactional;
use Modules\Press\Api\PrintHeadline;
use Modules\Press\Models\Headline;

final class PressHandlers
{
    #[CommandHandler]
    #[Authorize]
    #[Transactional]
    public function print(
        PrintHeadline $command,
        #[CurrentUser] Authenticatable $actor,
        #[Header('correlation_id')] string $correlationId,
    ): int {
        return Headline::query()->firstOrCreate(['publication_id' => $command->publicationId], [
            'nickname_id' => $command->nicknameId,
            'member_id' => $command->memberId,
            'printed_by' => $actor->getAuthIdentifier(),
            'correlation_id' => $correlationId,
        ])->id;
    }
}
