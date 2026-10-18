<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\NicknameListeners;

use Illuminate\Support\Facades\DB;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Recorder;
use Modules\Band\Api\NicknameGiven;

final class RecordTransactionLevel
{
    #[EventHandler]
    public function onNicknameGiven(NicknameGiven $event): void
    {
        Recorder::record('listener transaction level', DB::transactionLevel());
    }
}
