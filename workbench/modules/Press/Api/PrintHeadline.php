<?php

declare(strict_types=1);

namespace Modules\Press\Api;

use Laravents\Concerns\Sendable;

/**
 * Command press.print_headline: print the headline about a nickname. The publication id is the idempotency key:
 * printing the same one twice prints once.
 */
final readonly class PrintHeadline
{
    use Sendable;

    public function __construct(
        public int $nicknameId,
        public int $memberId,
        public string $publicationId,
    ) {}
}
