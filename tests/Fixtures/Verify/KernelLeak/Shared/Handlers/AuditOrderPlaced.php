<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Handlers;

use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Verify\KernelLeak\Orders\Api\OrderPlaced;

/** The shared kernel listening to a module that uses it: a cycle. */
final class AuditOrderPlaced
{
    #[EventHandler]
    public function __invoke(OrderPlaced $event): void {}
}
