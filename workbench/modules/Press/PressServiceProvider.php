<?php

declare(strict_types=1);

namespace Modules\Press;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PressServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Any authenticated user; a guest is refused because the user parameter is not nullable.
        Gate::define('press.print_headline', static fn (Authenticatable $user): bool => true);
    }
}
