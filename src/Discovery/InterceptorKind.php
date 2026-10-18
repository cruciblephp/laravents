<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Laravents\Attributes\After;
use Laravents\Attributes\Around;
use Laravents\Attributes\Before;

enum InterceptorKind: string
{
    case Before = 'before';
    case Around = 'around';
    case After = 'after';

    /** @return class-string<Before|Around|After> */
    public function attribute(): string
    {
        return match ($this) {
            self::Before => Before::class,
            self::Around => Around::class,
            self::After => After::class,
        };
    }
}
