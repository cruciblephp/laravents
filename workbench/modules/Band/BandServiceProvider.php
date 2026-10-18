<?php

declare(strict_types=1);

namespace Modules\Band;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\ReplaceMember;
use Workbench\App\Models\User;

final class BandServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Only a member gives nicknames, and never to themselves; a fan may ask, not give.
        Gate::define('band.give_nickname', static fn (User $user, GiveNickname $command): bool => $user->is_member && $user->getAuthIdentifier() !== $command->memberId);

        // A member replaces another, never themselves.
        Gate::define('band.replace_member', static fn (User $user, ReplaceMember $command): bool => $user->is_member && $user->getAuthIdentifier() !== $command->outId);
    }
}
