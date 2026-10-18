<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use PhpToken;
use Symfony\Component\Finder\Finder;

/**
 * Finds the classes in the configured paths by reading each file's declarations with PHP's own tokenizer, nothing
 * loaded and no dependency beyond Laravel's Finder. Files are ordered by path, as Finder's sortByName() orders them,
 * which under PSR-4 follows the class name; the order is stable, and it is the discovery order.
 */
final class ClassDiscovery
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public static function in(array $paths): array
    {
        return self::declared($paths, [T_CLASS]);
    }

    /**
     * The classes, interfaces, traits and enums in the configured paths, in the same order: what the dependency
     * scanner reads, since an interface or an enum can cross a module boundary as well as a class.
     *
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public static function structures(array $paths): array
    {
        return self::declared($paths, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]);
    }

    /**
     * A file's tokens without whitespace and comments: what discovery and the dependency scanner both read.
     *
     * @return list<PhpToken>
     */
    public static function tokens(string $code): array
    {
        return array_values(array_filter(PhpToken::tokenize($code), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    /**
     * Every name declared after one of the keywords, qualified by the file's namespace. `new class` and `::class`
     * are not followed by a name, so they declare nothing; a name declared twice (a polyfill's if/else) counts once.
     *
     * @param  list<string>  $paths
     * @param  list<int>  $keywords
     * @return list<class-string>
     */
    private static function declared(array $paths, array $keywords): array
    {
        $paths = array_values(array_filter($paths, is_dir(...)));

        if ($paths === []) {
            return [];
        }

        $declared = [];

        foreach (Finder::create()->files()->name('*.php')->in($paths)->sortByName() as $file) {
            $tokens = self::tokens($file->getContents());
            $namespace = '';

            foreach ($tokens as $i => $token) {
                $next = $tokens[$i + 1] ?? null;

                if ($token->is(T_NAMESPACE)) {
                    $namespace = $next?->is([T_STRING, T_NAME_QUALIFIED]) === true ? $next->text.'\\' : '';
                } elseif ($token->is($keywords) && $next?->is(T_STRING) === true) {
                    $declared[] = $namespace.$next->text;
                }
            }
        }

        /** @var list<class-string> */
        return array_values(array_unique($declared));
    }
}
