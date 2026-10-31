<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Log\Context\Repository as Context;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gives every message its ids and actor, and runs the rest of the dispatch with them in Context.
 * A message dispatched inside a handler reads its parent from Context: it keeps the correlation_id
 * and its causation_id is the parent's message_id. Afterwards only the metadata keys are put back,
 * so anything else a handler adds to Context stays.
 */
final readonly class MetadataPipe
{
    /**
     * Headers only Laravents sets; publication_id is set by the job that replays an asynchronous handler.
     * A caller may pass correlation_id to join an existing trace.
     */
    public const array RESERVED = ['message_id', 'causation_id', 'actor_id', 'actor_guard', 'timestamp', 'publication_id'];

    public function __construct(
        private Context $context,
        private AuthManager $auth,
        private ContextMetadata $metadata,
    ) {}

    /**
     * Refuses a header only Laravents may set. Also called by the fake, so a test cannot pass what production refuses.
     *
     * @param  array<string, scalar|null>  $headers
     */
    public static function refuseReserved(array $headers): void
    {
        foreach (self::RESERVED as $header) {
            if (array_key_exists($header, $headers)) {
                throw new InvalidArgumentException(sprintf('Header [%s] is set by Laravents and cannot be passed.', $header));
            }
        }
    }

    public function handle(Message $message, Closure $next): mixed
    {
        self::refuseReserved($message->headers);

        $messageId = (string) Str::ulid();
        [$actorId, $actorGuard] = $this->actor();

        $metadata = array_filter([
            'message_id' => $messageId,
            'correlation_id' => HeaderValue::string($message->headers['correlation_id'] ?? null)
                ?? HeaderValue::string($this->context->get('correlation_id'))
                ?? $messageId,
            'causation_id' => HeaderValue::string($this->context->get('message_id')),
            'actor_id' => $actorId,
            'actor_guard' => $actorGuard,
        ], static fn (?string $value): bool => $value !== null);

        $message = $message->withHeaders([
            ...$message->headers,
            ...$metadata,
            // ISO 8601 in UTC, microseconds and Z: the form Eloquent serializes every date to (Timestamp).
            'timestamp' => Timestamp::now(),
        ]);

        return $this->metadata->within($metadata, static fn (): mixed => $next($message));
    }

    /**
     * The parent message's actor when there is one, else whoever the default guard has authenticated.
     *
     * @return array{?string, ?string}
     */
    private function actor(): array
    {
        $id = HeaderValue::string($this->context->get('actor_id'));

        if ($id !== null) {
            return [$id, HeaderValue::string($this->context->get('actor_guard'))];
        }

        $id = HeaderValue::string($this->auth->guard()->id());

        return $id === null ? [null, null] : [$id, $this->auth->getDefaultDriver()];
    }
}
