<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\HandlerAttributes;

use Attribute;

/** An application's own attribute beside #[Expose], on a handler's method or class, more than once. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Label
{
    public function __construct(public string $text) {}
}
