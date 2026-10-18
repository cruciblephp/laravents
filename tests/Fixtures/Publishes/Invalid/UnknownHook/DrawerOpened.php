<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Invalid\UnknownHook;

final readonly class DrawerOpened
{
    public function __construct(public int $id) {}
}
