<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Api;

/** Command ledger.post: writes an entry and publishes Posted. */
final readonly class Post
{
    public function __construct(public int $amount) {}
}
