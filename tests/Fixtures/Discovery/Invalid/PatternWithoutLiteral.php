<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Discovery\Invalid;

use Laravents\Attributes\EventHandler;

final class PatternWithoutLiteral
{
    #[EventHandler('*.*')]
    public function on(mixed $event): void {}
}
