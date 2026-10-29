<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\HandlerAttributes;

use Attribute;

/** A Label of its own kind: asking for Label finds it too. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class LoudLabel extends Label {}
