<?php

declare(strict_types=1);

namespace Laravents\Verify;

use Closure;
use PhpToken;
use ReflectionClass;
use Throwable;

/**
 * The classes each module class references, read from its file with PhpToken::tokenize(), with no parser and no
 * dependency of its own. A name is a reference when, resolved as PHP resolves class names, it names an existing class,
 * interface, trait or enum, and it is not:
 *
 * - a member's name (after `->`, `?->`, `::`) or what is being declared (after `class`, `function`, `fn`, `case`,
 *   `goto`, `as`, or before `=` as a constant's name);
 * - a function call (`name(`, unless after `new` or opening an attribute), a named argument or a label (`name:`),
 *   a method in a trait adaptation (`run as walk`, `Trait::run as protected walk`);
 * - a built-in type, `self` or `parent`.
 *
 * Imports are read whole: `use` (single, aliased, several, grouped), skipping `use function` and `use const`; an
 * imported name is an alias, and a reference when it is a class-like (`use Modules\Band\Api;` imports a namespace).
 * Names resolve by their first segment against the imports (case-insensitively), else against the current namespace;
 * each `namespace` resets the imports. Comments, docblocks and strings are never read. The unit is the file: every
 * class a file declares is given the file's references, which under PSR-4 (one class per file) is the class's own.
 *
 * What it does not see: class names in strings, names built at run time (`new $class`, `app($name)`), types written
 * only in docblocks, and functions and constants, which name no class. A constant spelled exactly as a class of the
 * same namespace would be counted. tests/Architecture and Crucible's own arch extraction remain the second check.
 */
final class DependencyScanner
{
    private const array BUILT_IN = ['array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'parent', 'self', 'string', 'true', 'void'];

    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** After these, a name is a member's or what is being declared, never a class. */
    private const array NOT_AFTER = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_FN, T_GOTO, T_CASE, T_AS];

    private const array VISIBILITY = [T_PUBLIC, T_PROTECTED, T_PRIVATE];

    /** @var list<PhpToken> */
    private array $tokens = [];

    private string $prefix = '';

    private string $namespace = '';

    /** @var array<string, string> lower-cased alias => fully qualified name */
    private array $aliases = [];

    /** @var array<string, int> */
    private array $found = [];

    /** @var array<string, bool> */
    private array $classes = [];

    /** @var Closure(string): bool */
    private readonly Closure $exists;

    /**
     * @param  (Closure(string): bool)|null  $exists  Whether a name is a class, interface, trait or enum (autoloading it)
     */
    public function __construct(private readonly string $rootNamespace, ?Closure $exists = null)
    {
        $this->exists = $exists ?? static function (string $name): bool {
            try {
                return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
            } catch (Throwable) {
                return false;
            }
        };
    }

    /**
     * Every reference from the given classes into the root namespace, other than a class's to itself, in the order
     * of the classes and then of the first reference.
     *
     * @param  list<class-string>  $classes
     * @return list<Dependency>
     */
    public function scan(array $classes): array
    {
        $prefix = trim($this->rootNamespace, '\\').'\\';
        $byFile = [];
        $dependencies = [];

        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();

            if ($file === false) {
                continue;
            }

            $byFile[$file] ??= $this->referencesIn((string) file_get_contents($file), $prefix);

            foreach ($byFile[$file] as $uses => $line) {
                if (strcasecmp($uses, $class) !== 0) {
                    $dependencies[] = new Dependency($class, $uses, $file, $line);
                }
            }
        }

        return $dependencies;
    }

    /**
     * The classes a piece of PHP references, each with the line of its first reference, in order of first
     * reference. With a prefix, only names under it are kept.
     *
     * @return array<string, int>
     */
    public function referencesIn(string $code, string $prefix = ''): array
    {
        $this->tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $this->prefix = $prefix;
        $this->namespace = '';
        $this->aliases = [];
        $this->found = [];
        $depth = 0;
        $importDepth = 0;
        /** @var list<bool> $brackets whether each open bracket is an attribute group */
        $brackets = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            $next = $this->tokens[$i + 1] ?? null;

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_ATTRIBUTE) || $token->text === '[') {
                $brackets[] = $token->is(T_ATTRIBUTE);
            } elseif ($token->text === ']') {
                array_pop($brackets);
            } elseif ($token->is(T_NAMESPACE)) {
                $this->aliases = [];
                $this->namespace = $next?->is(self::NAMES) === true ? $next->text : '';
                $i += $this->namespace === '' ? 0 : 1;
                $importDepth = ($this->tokens[$i + 1] ?? null)?->text === '{' ? $depth + 1 : $depth;
            } elseif ($token->is(T_USE) && $depth === $importDepth && $next?->text !== '(') {
                $i = $this->import($i);
            } elseif ($token->is(self::NAMES) && $this->isClassReference($i, end($brackets))) {
                $this->record($this->resolve($token), $token->line);
            }
        }

        return $this->found;
    }

    private function isClassReference(int $i, bool $inAttribute): bool
    {
        $token = $this->tokens[$i];
        $previous = $this->tokens[$i - 1] ?? null;
        $next = $this->tokens[$i + 1] ?? null;

        if ($previous?->is(self::NOT_AFTER) === true
            || $next?->is(T_AS) === true
            || ($token->is(T_STRING) && in_array(strtolower($token->text), self::BUILT_IN, true))
            || ($previous?->is(self::VISIBILITY) === true && ($this->tokens[$i - 2] ?? null)?->is(T_AS) === true)) {
            return false;
        }

        return match ($next?->text) {
            ':', '=' => false,
            // An attribute's arguments are constant expressions: a call there is the attribute's own name, or new.
            '(' => $previous?->is(T_NEW) === true || $inAttribute,
            default => true,
        };
    }

    /**
     * An import: `use A\B;`, `use A\B as C, D\E;`, `use A\{B, C\D as E, function f, const X};`, `use function ...`.
     * Every class it names is a reference and becomes an alias.
     */
    private function import(int $i): int
    {
        $j = $i + 1;
        $classLike = true;

        if (($this->tokens[$j] ?? null)?->is([T_FUNCTION, T_CONST]) === true) {
            $classLike = false;
            $j++;
        }

        while (($this->tokens[$j] ?? null)?->is(self::NAMES) === true) {
            $name = $this->tokens[$j];
            $j++;

            // A separator after an imported name only opens a group: `use A\{B, C};`.
            if ($this->tokens[$j]->is(T_NS_SEPARATOR)) {
                return $this->importGroup(ltrim($name->text, '\\'), $j + 2, $classLike);
            }

            $alias = null;

            if ($this->tokens[$j]->is(T_AS)) {
                $alias = $this->tokens[$j + 1]->text;
                $j += 2;
            }

            if ($classLike) {
                $this->alias(ltrim($name->text, '\\'), $alias, $name->line);
            }

            if ($this->tokens[$j]->text !== ',') {
                return $j;
            }

            $j++;
        }

        // Only invalid PHP ends an import without a name.
        return $i;
    }

    /** The items of a group import, from after `{`; returns the index of the closing `}`. */
    private function importGroup(string $prefix, int $j, bool $classLike): int
    {
        $count = count($this->tokens);

        while ($j < $count && $this->tokens[$j]->text !== '}') {
            $itemIsClass = $classLike;

            if ($this->tokens[$j]->is([T_FUNCTION, T_CONST])) {
                $itemIsClass = false;
                $j++;
            }

            $name = $this->tokens[$j];
            $alias = null;
            $j++;

            if (($this->tokens[$j] ?? null)?->is(T_AS) === true) {
                $alias = $this->tokens[$j + 1]->text;
                $j += 2;
            }

            if ($itemIsClass && $name->is(self::NAMES)) {
                $this->alias($prefix.'\\'.$name->text, $alias, $name->line);
            }

            if (($this->tokens[$j] ?? null)?->text === ',') {
                $j++;
            }
        }

        return $j;
    }

    private function alias(string $class, ?string $alias, int $line): void
    {
        $this->aliases[strtolower($alias ?? substr((string) strrchr('\\'.$class, '\\'), 1))] = $class;
        $this->record($class, $line);
    }

    private function resolve(PhpToken $token): string
    {
        $text = $token->text;

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return substr($text, 1);
        }

        if ($token->is(T_NAME_RELATIVE)) {
            return $this->qualify(substr($text, (int) strpos($text, '\\') + 1));
        }

        $first = explode('\\', $text, 2)[0];
        $imported = $this->aliases[strtolower($first)] ?? null;

        return $imported === null ? $this->qualify($text) : $imported.substr($text, strlen($first));
    }

    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    private function record(string $class, int $line): void
    {
        if (($this->prefix === '' || str_starts_with($class, $this->prefix)) && ($this->classes[$class] ??= ($this->exists)($class))) {
            $this->found[$class] ??= $line;
        }
    }
}
