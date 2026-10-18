<?php

declare(strict_types=1);

namespace Laravents\Verify;

/**
 * One class referencing another: where the first reference is, by file and line.
 */
final readonly class Dependency
{
    /**
     * @param  string  $class  The referencing class
     * @param  string  $uses  The referenced class, as PHP resolves the name; it need not exist
     */
    public function __construct(
        public string $class,
        public string $uses,
        public string $file,
        public int $line,
    ) {}
}
