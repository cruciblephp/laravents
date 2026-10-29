<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Manifests;

/** The fixtures' schema: one required field, and one optional field of each kind of type the reader checks. */
final readonly class Manifest
{
    /** @param list<string> $tags */
    public function __construct(
        public string $version,
        public int $weight = 0,
        public float $ratio = 0.0,
        public bool $stable = false,
        public array $tags = [],
        public ?string $note = null,
        public int|string $id = 0,
        public mixed $extra = null,
    ) {}
}
