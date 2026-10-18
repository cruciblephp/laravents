<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Runs the handler, and everything inside it, in one database transaction: DB::transaction($callback, $attempts),
 * so a deadlock or other concurrency error is retried up to $attempts times, as Laravel does.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Transactional
{
    /** @param positive-int $attempts */
    public function __construct(
        public ?string $connection = null,
        public int $attempts = 1,
    ) {}
}
