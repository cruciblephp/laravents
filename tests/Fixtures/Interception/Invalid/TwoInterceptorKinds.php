<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Invalid;

use Laravents\Attributes\Around;
use Laravents\Attributes\Before;
use Laravents\Invocation\MethodInvocation;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Traced;

final class TwoInterceptorKinds
{
    #[Before(pointcut: Traced::class)]
    #[Around(pointcut: Traced::class)]
    public function both(MethodInvocation $invocation): mixed
    {
        return $invocation->proceed();
    }
}
