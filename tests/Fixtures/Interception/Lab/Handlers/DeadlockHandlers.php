<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Handlers;

use Illuminate\Database\QueryException;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Transactional;
use Laravents\Tests\Fixtures\Interception\Lab\Api\FailOnDeadlock;
use Laravents\Tests\Fixtures\Interception\Lab\Api\RetryOnDeadlock;
use Laravents\Tests\Fixtures\Recorder;
use PDOException;

/**
 * Each handler hits a deadlock on its first attempt and succeeds on the next.
 */
final class DeadlockHandlers
{
    #[CommandHandler]
    #[Transactional(attempts: 2)]
    public function retry(RetryOnDeadlock $command): int
    {
        return $this->deadlockOnce();
    }

    #[CommandHandler]
    #[Transactional]
    public function fail(FailOnDeadlock $command): int
    {
        return $this->deadlockOnce();
    }

    private function deadlockOnce(): int
    {
        Recorder::record('attempt');
        $attempt = count(Recorder::calls());

        if ($attempt === 1) {
            throw new QueryException('sqlite', 'update stock set reserved = 1', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
        }

        return $attempt;
    }
}
