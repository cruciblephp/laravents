<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Interception\Lab\Interceptors;

use Illuminate\Container\Attributes\Context as FromContext;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\After;
use Laravents\Attributes\Around;
use Laravents\Attributes\Before;
use Laravents\Attributes\Header;
use Laravents\Bus\Message;
use Laravents\Invocation\MethodInvocation;
use Laravents\Tests\Fixtures\Interception\Lab\Api\AuditMe;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Contextual;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Limited;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\ShortCircuit;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Traced;
use Laravents\Tests\Fixtures\Interception\Lab\Contracts\Audited;
use Laravents\Tests\Fixtures\Recorder;

final class LabInterceptors
{
    #[After(pointcut: Traced::class, precedence: 5)]
    public function after(mixed $result): void
    {
        Recorder::record('after', $result);
    }

    #[Around(pointcut: Traced::class)]
    public function around(MethodInvocation $invocation): mixed
    {
        Recorder::record('around in', $invocation->handler()->method);
        $result = $invocation->proceed();
        Recorder::record('around out');

        return $result;
    }

    #[Before(pointcut: Traced::class, precedence: -5)]
    public function before(Message $message): void
    {
        Recorder::record('before', $message->routingKey);
    }

    #[Around(pointcut: Limited::class)]
    public function limit(MethodInvocation $invocation, Limited $rule, #[Header('correlation_id')] string $correlationId): mixed
    {
        Recorder::record('limit', [$rule->max, $correlationId]);
        $result = $invocation->proceed();

        return is_int($result) ? $result + 10 : $result;
    }

    #[Around(pointcut: ShortCircuit::class)]
    public function shortCircuit(MethodInvocation $invocation): string
    {
        return 'from interceptor';
    }

    #[Before(pointcut: Audited::class)]
    public function audited(AuditMe $command): void
    {
        Recorder::record('audited', $command->who);
    }

    #[Before(pointcut: Contextual::class)]
    public function contextual(
        #[CurrentUser] ?Authenticatable $user,
        #[FromContext('correlation_id')] string $fromContext,
        #[Header('correlation_id')] string $fromHeader,
    ): void {
        Recorder::record('contextual', [$user?->getAuthIdentifier(), $fromContext, $fromHeader]);
    }
}
