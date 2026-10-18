<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Deferred\Mail\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Stamped {}
