<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

use function Orchestra\Testbench\workbench_path;

/**
 * The workbench application's configuration, the part an application would write in its own config files: the
 * modules live in workbench/modules and the users are the workbench's. Loaded by testbench.yaml, so the test suite
 * and `vendor/bin/testbench` (serve, laravents:docs, ...) see the same application.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make('config')->set([
            'auth.providers.users.model' => User::class,
            'laravents.paths' => [workbench_path('modules')],
            'laravents.modules.path' => workbench_path('modules'),
        ]);
    }
}
