<?php

declare(strict_types=1);

namespace Modules\FanClub\Api;

use Laravents\Concerns\Sendable;

/**
 * Command fan_club.welcome_fan: welcome a new fan. The publication id is the idempotency key: welcoming the same
 * one twice welcomes once.
 */
final readonly class WelcomeFan
{
    use Sendable;

    public function __construct(
        public int $userId,
        public string $publicationId,
    ) {}
}
