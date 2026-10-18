<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Deferred\Mail\Api;

final readonly class SignedUp
{
    public function __construct(public string $email, public bool $failLater = false) {}
}
