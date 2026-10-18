<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Patterns\Chat\Api;

/** Event chat.opened by its derived key: published as an object. */
final readonly class ChatOpened
{
    public function __construct(public int $chatId) {}
}
