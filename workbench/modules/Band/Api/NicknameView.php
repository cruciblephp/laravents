<?php

declare(strict_types=1);

namespace Modules\Band\Api;

use Carbon\CarbonImmutable;

/**
 * Result of the band.get_nickname query.
 */
final readonly class NicknameView
{
    public function __construct(
        public int $id,
        public int $givenBy,
        public int $memberId,
        public string $nickname,
        public CarbonImmutable $givenAt,
    ) {}
}
