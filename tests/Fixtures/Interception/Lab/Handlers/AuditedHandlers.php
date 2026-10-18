<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Tests\Fixtures\Interception\Lab\Api\AuditMe;
use Laravents\Tests\Fixtures\Interception\Lab\Contracts\Audited;
use Laravents\Tests\Fixtures\Recorder;

final class AuditedHandlers implements Audited
{
    #[CommandHandler]
    public function audit(AuditMe $command): string
    {
        Recorder::record('audit handler');

        return 'audited';
    }
}
