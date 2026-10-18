<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\NicknameListeners;

use Laravents\Attributes\EventHandler;
use Modules\Band\Api\NicknameGiven;
use RuntimeException;

final class ThrowOnNicknameGiven
{
    #[EventHandler]
    public function onNicknameGiven(NicknameGiven $event): never
    {
        throw new RuntimeException('Listener refused the nickname.');
    }
}
