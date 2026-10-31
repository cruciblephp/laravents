<?php

declare(strict_types=1);

namespace Laravents\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * Orchestra Testbench with the workbench as the application: its providers, migrations and routes come from
 * testbench.yaml (WithWorkbench), its configuration from Workbench\App\Providers\WorkbenchServiceProvider, the
 * example modules from workbench/modules. LaraventsServiceProvider comes from package discovery. What is set here is
 * the test environment only.
 *
 * Concrete, not abstract: Crucible's Pest dialect refuses an abstract class in pest()->extend().
 */
class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /** Package discovery on: LaraventsServiceProvider and the Laravents alias come from composer.json's extra.laravel. */
    protected $enablesPackageDiscoveries = true;

    /**
     * Before and after every test, the registry cache and any file written beside it are removed with plain PHP, not
     * with RegistryCache::clear(): cleanup must not run the code under test. Before, too: a process killed mid-test
     * (a mutant that timed out) runs no teardown, and its file must not be there when the next test boots.
     */
    protected function setUp(): void
    {
        $this->forgetRegistryCache();

        parent::setUp();

        $this->beforeApplicationDestroyed($this->forgetRegistryCache(...));
    }

    private function forgetRegistryCache(): void
    {
        $directory = default_skeleton_path('bootstrap/cache');
        $cache = $directory === false ? null : $directory.'/laravents.php';

        foreach ($cache === null ? [] : [$cache, ...(glob($cache.'.*.tmp') ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set([
            'app.key' => 'base64:'.base64_encode('laravents-workbench-key-32-bytes'),
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            // What Laravel's phpunit.xml pins, so a .env in Testbench's skeleton (the testbench CLI writes one) changes nothing.
            'cache.default' => 'array',
            'session.driver' => 'array',
            'mail.default' => 'array',
            // The database queue on the test connection.
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'testing',
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => 'testing',
        ]);
    }
}
