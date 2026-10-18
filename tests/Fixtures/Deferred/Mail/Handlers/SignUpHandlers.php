<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Deferred\Mail\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Transactional;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Deferred\Mail\Api\SignedUp;
use RuntimeException;

final class SignUpHandlers
{
    /** @param array{email: string, rollback?: bool} $payload */
    #[CommandHandler('mail.sign_up')]
    #[Transactional]
    public function signUp(array $payload, EventBus $events): void
    {
        $events->publish(new SignedUp($payload['email']));

        if ($payload['rollback'] ?? false) {
            throw new RuntimeException('Sign-up rolled back.');
        }
    }
}
