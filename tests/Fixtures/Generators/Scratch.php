<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Generators;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A throwaway base path for the generators: a directory under the system temp dir with its own
 * bootstrap/providers.php, and a modules namespace unique to the test, autoloaded from it. Nothing is written
 * into the repository, so neither the boundary test (which globs modules/) nor any other run can see it.
 */
final class Scratch
{
    public static string $path = '';

    /** The modules namespace, unique per test: PHP cannot unload a class, so names must never repeat. */
    public static string $namespace = '';

    private static string $repositoryBase = '';

    private static string $providers = '';

    private static ?Closure $autoloader = null;

    public static function create(): void
    {
        self::$repositoryBase = base_path();
        self::$providers = (string) file_get_contents(self::$repositoryBase.'/bootstrap/providers.php');
        self::$path = sys_get_temp_dir().'/laravents-generators-'.bin2hex(random_bytes(8));
        self::$namespace = 'Scratch'.bin2hex(random_bytes(8));

        File::ensureDirectoryExists(self::$path.'/bootstrap');
        File::put(self::$path.'/bootstrap/providers.php', self::$providers);

        app()->setBasePath(self::$path);
        config([
            'laravents.modules.namespace' => self::$namespace,
            'laravents.modules.path' => self::$path.'/modules',
        ]);

        $prefix = self::$namespace.'\\';
        $modules = self::$path.'/modules/';
        self::$autoloader = static function (string $class) use ($prefix, $modules): void {
            if (str_starts_with($class, $prefix) && is_file($file = $modules.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
                require $file;
            }
        };
        spl_autoload_register(self::$autoloader);
    }

    public static function destroy(): void
    {
        if (self::$autoloader instanceof Closure) {
            spl_autoload_unregister(self::$autoloader);
            self::$autoloader = null;
        }

        if (self::$path !== '' && str_starts_with(self::$path, sys_get_temp_dir())) {
            File::deleteDirectory(self::$path);
        }

        // Belt and braces: the generators only ever saw the scratch bootstrap file, but the repository's must be as it was.
        $repository = self::$repositoryBase.'/bootstrap/providers.php';

        if (self::$repositoryBase !== '' && file_get_contents($repository) !== self::$providers) {
            file_put_contents($repository, self::$providers);

            throw new RuntimeException('A generator wrote the repository\'s bootstrap/providers.php; it has been restored.');
        }
    }

    /** A path under the scratch base. */
    public static function path(string $path = ''): string
    {
        return self::$path.($path !== '' ? '/'.$path : '');
    }

    /**
     * A generated class in the scratch modules namespace, loaded.
     *
     * @return class-string
     */
    public static function loaded(string $class): string
    {
        $class = self::class($class);

        return class_exists($class) ? $class : throw new RuntimeException(sprintf('[%s] was not generated.', $class));
    }

    /** A class name in the scratch modules namespace. */
    public static function class(string $class): string
    {
        return self::$namespace.'\\'.$class;
    }
}
