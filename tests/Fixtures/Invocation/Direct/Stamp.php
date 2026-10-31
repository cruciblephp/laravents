<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Invocation\Direct;

use Attribute;

/** A parameter attribute: the container's attribute callbacks see the argument given to it. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Stamp {}
