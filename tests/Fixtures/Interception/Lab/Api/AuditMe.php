<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Api;

final readonly class AuditMe
{
    public function __construct(public string $who) {}
}
