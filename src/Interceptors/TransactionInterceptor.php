<?php

declare(strict_types=1);

namespace Laravents\Interceptors;

use Illuminate\Database\DatabaseManager;
use Laravents\Attributes\Around;
use Laravents\Attributes\Transactional;
use Laravents\Invocation\MethodInvocation;

/**
 * #[Transactional]: the handler and everything inside it, sync event handlers included, commit or roll back together.
 * The transaction is recorded by Laravel's transaction manager under the connection's name, so outbox rows of
 * events published inside it are written on that connection (PublisherConnection).
 */
final readonly class TransactionInterceptor
{
    public function __construct(private DatabaseManager $database) {}

    #[Around(pointcut: Transactional::class, precedence: -500)]
    public function transaction(MethodInvocation $invocation, Transactional $rule): mixed
    {
        return $this->database->connection($rule->connection)->transaction(
            static fn (): mixed => $invocation->proceed(),
            $rule->attempts,
        );
    }
}
