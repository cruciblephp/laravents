<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Invalid;

use Laravents\Attributes\Before;

final class UnknownPointcut
{
    #[Before(pointcut: 'Laravents\Tests\Fixtures\Interception\Nope')]
    public function before(): void {}
}
