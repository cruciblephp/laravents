<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Auth\Office\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\Header;
use Laravents\Tests\Fixtures\Auth\Office\Api\Greeted;
use Laravents\Tests\Fixtures\Recorder;

final class GreetedListener
{
    #[EventHandler]
    #[Asynchronous('office')]
    public function __invoke(Greeted $event, #[CurrentUser] ?Authenticatable $user, #[Header('actor_id')] ?string $actorId = null): void
    {
        Recorder::record('greeted', [$user?->getAuthIdentifier(), $actorId]);
    }
}
