<?php

declare(strict_types=1);

namespace Laravents\Interceptors;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\Before;
use Laravents\Bus\Message;
use Laravents\Exceptions\ActorMismatchException;

/**
 * #[Authorize]: the actor must pass the Gate before the handler, or anything around it, runs.
 */
final readonly class AuthorizationInterceptor
{
    public function __construct(
        private Gate $gate,
        private AuthManager $auth,
    ) {}

    #[Before(pointcut: Authorize::class, precedence: -1000)]
    public function authorize(Message $message, Authorize $rule): void
    {
        $actor = $this->actor($message);
        $response = $this->gate->forUser($actor)->inspect($rule->ability ?? $message->routingKey, [$message->payload]);

        if ($response->allowed()) {
            return;
        }

        if (! $actor instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        $response->authorize();
    }

    /**
     * The user on the guard the actor headers name. The headers come from that guard or from a parent
     * message, so a mismatch means something put the wrong actor in Context.
     */
    private function actor(Message $message): ?Authenticatable
    {
        $id = $message->headers['actor_id'] ?? null;
        $guard = $message->headers['actor_guard'] ?? null;

        if (! is_string($id) || ! is_string($guard)) {
            return null;
        }

        $user = $this->auth->guard($guard)->user();

        if (! $user instanceof Authenticatable || ! is_scalar($user->getAuthIdentifier()) || (string) $user->getAuthIdentifier() !== $id) {
            throw ActorMismatchException::for($guard, $id);
        }

        return $user;
    }
}
