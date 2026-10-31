<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Invocation\Direct\Handlers;

/** Bound in DirectHandlers' place: its own voice() answers. */
final class LouderHandlers extends DirectHandlers
{
    /** @param  array<string, mixed>  $payload */
    public function voice(array $payload): string
    {
        return 'loud';
    }
}
