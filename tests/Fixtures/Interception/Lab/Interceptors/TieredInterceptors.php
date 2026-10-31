<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Interceptors;

use Laravents\Attributes\Before;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Tiered;

final class TieredInterceptors
{
    #[Before(pointcut: Tiered::class)]
    public function check(Tiered $tiered): void {}
}
