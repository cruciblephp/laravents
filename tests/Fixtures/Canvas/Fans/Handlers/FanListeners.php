<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Canvas\Fans\Handlers;

use Illuminate\Auth\Events\Registered;
use Laravents\Attributes\EventHandler;

final class FanListeners
{
    /** Keyed: reached by Laravents::publish('fans.signed_up', ...) only, never by Laravel's dispatcher. */
    #[EventHandler('fans.signed_up')]
    public function signedUp(Registered $event): void {}
}
