<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The registry as one PHP file, the way route:cache keeps the routes: scan in development, cache for production.
 *
 * The file is `<?php return [...];`, written by var_export(): the registry as plain data (Registry::toArray()), which
 * opcache keeps in shared memory as it is, so a cached boot reads it without parsing or unserializing anything. Beside
 * it, the format and a fingerprint of the configuration discovery read (fingerprint()).
 *
 * Absent, unreadable, written in another format or for another configuration: load() answers null, and the registry
 * is scanned. A file that is there but wrong is as inert as one that is missing. Whether a fingerprint that no longer
 * matches counts as wrong is `laravents.cache.stale`: `scan` (the default), or `cache`, which keeps reading the file
 * until the next laravents:cache.
 */
final readonly class RegistryCache
{
    /** The shape of the file. A file of any other format is not read. */
    public const int FORMAT = 2;

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

    /**
     * A hash of what discovery read from the configuration, and of the Laravents version and file format it was cached
     * by: the scanned paths (relative to the base path, so a release moved as a whole keeps its cache), the modules
     * namespace, the disabled modules and the manifest settings. Keys and the disabled list are sorted first, so an
     * order is not a change. Computed on every boot that reads the cache, so the configuration is read in one lookup.
     */
    public static function fingerprint(Repository $config, string $basePath): string
    {
        $laravents = $config->get('laravents');
        $laravents = is_array($laravents) ? $laravents : [];
        $modules = is_array($laravents['modules'] ?? null) ? $laravents['modules'] : [];
        $base = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = static fn (mixed $path): mixed => is_string($path) && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
        $disabled = is_array($modules['disabled'] ?? null) ? array_values(array_unique(array_filter($modules['disabled'], is_string(...)))) : [];
        sort($disabled);

        $values = [
            'disabled' => $disabled,
            'format' => self::FORMAT,
            'laravents' => InstalledVersions::isInstalled('laravents/laravents') ? InstalledVersions::getPrettyVersion('laravents/laravents') : null,
            'manifest' => $modules['manifest'] ?? null,
            'manifest_required' => $modules['manifest_required'] ?? null,
            'modules_path' => $relative($modules['path'] ?? null),
            'namespace' => $modules['namespace'] ?? null,
            'paths' => array_map($relative, is_array($laravents['paths'] ?? null) ? $laravents['paths'] : []),
        ];

        return hash('xxh128', (string) json_encode($values));
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

    /**
     * The cached registry, or null when there is none this version can trust: no file, an unreadable one, another
     * format, or a fingerprint other than $fingerprint (null skips that check). One answer for all of them, since
     * the only right response to any of them is to scan.
     *
     * @param  Closure(class-string, array<string, mixed>): object  $manifests  rebuilds a module manifest, as discovery built it
     */
    public function load(?string $fingerprint, Closure $manifests): ?Registry
    {
        if (! $this->exists()) {
            return null;
        }

        try {
            // A file that does not parse throws a ParseError, which `@` does not silence.
            /** @var mixed $file */
            $file = @include $this->path;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($file) || ($file['format'] ?? null) !== self::FORMAT) {
            return null;
        }

        if ($fingerprint !== null && ($file['fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }

        try {
            // Anything but the array toArray() wrote fails fromArray()'s type or its lists, and reads as no cache.
            /** @var array<string, mixed> $registry */
            $registry = $file['registry'] ?? null;

            return Registry::fromArray($registry, $manifests);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write the registry to a file beside the cache, then rename it over the cache, so a request never includes half
     * a file. A write or rename that fails removes the file beside it and throws, leaving the cache as it was. The
     * file is dropped from this process's opcache. PHP-FPM keeps its own: it reads the new file as its
     * opcache.validate_timestamps allows, or once it is reloaded, as with Laravel's config and route caches.
     *
     * @throws RuntimeException when the cache's directory cannot be made, or the file cannot be written
     */
    public function write(Registry $registry, string $fingerprint): void
    {
        $directory = dirname($this->path);

        // Made if it is missing: mkdir fails quietly on a directory that is there, or that another process just made.
        @mkdir($directory, 0755, true);

        if (! is_dir($directory)) {
            throw new RuntimeException(sprintf('The Laravents registry cache [%s] cannot be written: its directory cannot be made.', $this->path));
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $contents = "<?php\n\n"
            ."// Generated by laravents:cache. Do not edit: laravents:clear removes it, laravents:cache writes it again.\n\n"
            .'return '.var_export([
                'format' => self::FORMAT,
                'fingerprint' => $fingerprint,
                'registry' => $registry->toArray(),
            ], true).";\n";

        // A short write (a full disk) answers false too, so the file renamed over the cache is always whole.
        if (@file_put_contents($temporary, $contents) === false || ! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('The Laravents registry cache [%s] cannot be written.', $this->path));
        }

        $this->forget($this->path);
    }

    /** @throws RuntimeException when the file is there and cannot be removed */
    public function clear(): void
    {
        // Already gone, or removed by another process meanwhile: either way there is no cache, which is what was asked.
        if (! @unlink($this->path) && is_file($this->path)) {
            throw new RuntimeException(sprintf('The Laravents registry cache [%s] cannot be removed.', $this->path));
        }

        $this->forget($this->path);
    }

    /** Drop the file from opcache, so the next include reads what is on disk now. */
    private function forget(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
