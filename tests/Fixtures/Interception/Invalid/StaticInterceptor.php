<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Invalid;

use Laravents\Attributes\Before;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Traced;

final class StaticInterceptor
{
    #[Before(pointcut: Traced::class)]
    public static function before(): void {}
}
