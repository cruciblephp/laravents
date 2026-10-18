<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Deferred\Mail\Api;

final readonly class WelcomeSent
{
    public function __construct(public string $email) {}
}
