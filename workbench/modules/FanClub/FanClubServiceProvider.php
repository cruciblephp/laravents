<?php

declare(strict_types=1);

namespace Modules\FanClub;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class FanClubServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Any authenticated user; a guest is refused because the user parameter is not nullable.
        Gate::define('fan_club.welcome_fan', static fn (Authenticatable $user): bool => true);
    }
}
