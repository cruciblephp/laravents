<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * The registry as one PHP file, the way route:cache keeps the routes: scan in development, cache for production.
 * The file is `<?php return unserialize(base64_decode('...'));`: the registry is plain data plus the pointcut
 * attribute instances its interceptors matched by, all of which serialize.
 */
final readonly class RegistryCache
{
    public function __construct(public string $path) {}

    /**
     * bootstrap/cache/laravents.php, or LARAVENTS_CACHE_PATH as APP_ROUTES_CACHE overrides the routes cache:
     * absolute as given, else relative to the base path.
     */
    public static function for(Application $app): self
    {
        $env = Env::get('LARAVENTS_CACHE_PATH');

        if (! is_string($env) || $env === '') {
            return new self($app->bootstrapPath('cache/laravents.php'));
        }

        return new self(Str::startsWith($env, ['/', '\\']) ? $env : $app->basePath($env));
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** The one line laravents:list and laravents:show print when the registry came from this file. */
    public function note(string $basePath): string
    {
        return sprintf(
            'Registry loaded from the cache [%s]. Run laravents:clear to scan again.',
            Str::after($this->path, rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR),
        );
    }

    public function load(): Registry
    {
        $registry = require $this->path;

        if (! $registry instanceof Registry) {
            throw new UnexpectedValueException(sprintf('The Laravents cache [%s] does not hold a registry; run php artisan laravents:clear.', $this->path));
        }

        return $registry;
    }

    public function write(Registry $registry): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $this->path,
            "<?php\n\nreturn unserialize(base64_decode('".base64_encode(serialize($registry))."'));\n",
            LOCK_EX,
        );
    }

    public function clear(): void
    {
        if ($this->exists()) {
            unlink($this->path);
        }
    }
}
