<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Invalid;

use Laravents\Attributes\Around;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Traced;

final class AroundWithoutInvocation
{
    #[Around(pointcut: Traced::class)]
    public function around(): mixed
    {
        return null;
    }
}
