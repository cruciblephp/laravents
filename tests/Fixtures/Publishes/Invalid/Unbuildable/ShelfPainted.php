<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Invalid\Unbuildable;

final readonly class ShelfPainted
{
    public function __construct(public int $id, public string $colour) {}
}
