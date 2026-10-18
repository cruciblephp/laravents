<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Auth\Office\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Header;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Auth\Office\Api\Announce;
use Laravents\Tests\Fixtures\Auth\Office\Api\Greet;
use Laravents\Tests\Fixtures\Auth\Office\Api\Greeted;
use Laravents\Tests\Fixtures\Auth\Office\Api\Stumble;
use RuntimeException;

/** #[Authorize]d commands that say who they ran as. */
final class OfficeHandlers
{
    /** @return array{current: mixed, actor_id: ?string, actor_guard: ?string, admin: mixed, tenant: ?string} */
    #[CommandHandler]
    #[Authorize]
    public function greet(
        Greet $command,
        #[CurrentUser] ?Authenticatable $user,
        #[Header('actor_id')] ?string $actorId = null,
        #[Header('actor_guard')] ?string $actorGuard = null,
        #[Header('tenant')] ?string $tenant = null,
    ): array {
        return [
            'current' => $user?->getAuthIdentifier(),
            'actor_id' => $actorId,
            'actor_guard' => $actorGuard,
            'admin' => auth()->guard('admin')->id(),
            'tenant' => $tenant,
        ];
    }

    #[CommandHandler]
    #[Authorize]
    public function stumble(Stumble $command): never
    {
        throw new RuntimeException('Stumbled.');
    }

    #[CommandHandler]
    #[Authorize]
    public function announce(Announce $command, EventBus $events, #[Header('message_id')] string $messageId): string
    {
        $events->publish(new Greeted);

        return $messageId;
    }
}
