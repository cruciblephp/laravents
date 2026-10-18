<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Verify\Kernel\Billing\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\EventHandler;
use Laravents\Tests\Fixtures\Verify\Kernel\Orders\Api\OrderPlaced;
use Laravents\Tests\Fixtures\Verify\Kernel\Shared\Api\Money;
use Workbench\App\Models\User;

/** Uses Orders\Api, the shared kernel's Api, Laravel's user contract and the application's user model: no violation. */
final class ChargeOnOrderPlaced
{
    public ?Money $charged = null;

    #[EventHandler]
    public function __invoke(OrderPlaced $event, #[CurrentUser] Authenticatable $actor): void
    {
        $this->charged = $event->total;
    }

    public function payer(int $id): ?User
    {
        return User::query()->find($id);
    }
}
