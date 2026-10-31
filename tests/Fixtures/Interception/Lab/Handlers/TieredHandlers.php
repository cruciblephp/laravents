<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Tier;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Tiered;

final class TieredHandlers
{
    /** @param  array<string, mixed>  $payload */
    #[CommandHandler('lab.tiered')]
    #[Tiered(Tier::Gold)]
    public function tiered(array $payload): string
    {
        return 'tiered';
    }
}
