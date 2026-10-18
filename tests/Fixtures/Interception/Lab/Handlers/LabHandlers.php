<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Look;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Peek;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Plain;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Run;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Skip;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Contextual;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Limited;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\ShortCircuit;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Traced;
use Laravents\Tests\Fixtures\Recorder;

final class LabHandlers
{
    #[CommandHandler]
    #[Traced]
    public function run(Run $command): string
    {
        Recorder::record('handler');

        return 'ran';
    }

    #[CommandHandler]
    public function plain(Plain $command): string
    {
        Recorder::record('plain');

        return 'plain';
    }

    #[QueryHandler]
    #[Limited(max: 3)]
    public function look(Look $query): int
    {
        return 1;
    }

    #[CommandHandler]
    #[ShortCircuit]
    public function skip(Skip $command): string
    {
        Recorder::record('skipped handler ran');

        return 'handler';
    }

    #[QueryHandler]
    #[Contextual]
    public function peek(Peek $query): string
    {
        return 'peeked';
    }
}
