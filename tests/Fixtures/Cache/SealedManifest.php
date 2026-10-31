<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Cache;

/**
 * A manifest whose description is a private field: discovery builds it from module.json, but the cache keeps only
 * public properties, so read back it would lose the description. laravents:cache refuses to write it.
 */
final readonly class SealedManifest
{
    public function __construct(
        public string $version,
        private string $description = '',
    ) {}

    public function description(): string
    {
        return $this->description;
    }
}
