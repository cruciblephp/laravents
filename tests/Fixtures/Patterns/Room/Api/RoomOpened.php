<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Patterns\Room\Api;

/** Event room.opened by its derived key: published as an object. */
final readonly class RoomOpened
{
    public function __construct(public int $roomId) {}
}
