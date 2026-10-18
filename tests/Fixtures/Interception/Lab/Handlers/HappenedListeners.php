<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Happened;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Traced;
use Laravents\Tests\Fixtures\Recorder;

#[Traced]
final class HappenedListeners
{
    #[EventHandler]
    public function first(Happened $event): void
    {
        Recorder::record('first listener');
    }

    #[EventHandler]
    public function second(Happened $event): void
    {
        Recorder::record('second listener');
    }
}
