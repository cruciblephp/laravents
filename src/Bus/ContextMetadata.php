<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Closure;
use Illuminate\Log\Context\Repository as Context;

/**
 * The metadata keys Laravents owns in Context, and the one way to set them for a dispatch: exactly the
 * given keys are present while the callback runs, and afterwards each is put back as it was (a key that
 * was absent is forgotten), also on an exception. Anything else in Context is left alone.
 */
final readonly class ContextMetadata
{
    public const array KEYS = ['message_id', 'correlation_id', 'causation_id', 'actor_id', 'actor_guard'];

    public function __construct(private Context $context) {}

    /**
     * The metadata keys among a message's headers, as strings.
     *
     * @param  array<string, scalar|null>  $headers
     * @return array<string, string>
     */
    public static function fromHeaders(array $headers): array
    {
        $metadata = [];

        foreach (self::KEYS as $key) {
            $value = HeaderValue::string($headers[$key] ?? null);

            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * @template T
     *
     * @param  array<string, string>  $metadata
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(array $metadata, Closure $callback): mixed
    {
        $before = $this->snapshot();
        $this->apply($metadata);

        try {
            return $callback();
        } finally {
            $this->restore($before);
        }
    }

    /**
     * The metadata keys Context holds now, as strings.
     *
     * @return array<string, string>
     */
    public function current(): array
    {
        return self::fromHeaders(array_filter(
            array_map(fn (string $key): mixed => $this->context->get($key), array_combine(self::KEYS, self::KEYS)),
            is_scalar(...),
        ));
    }

    /** @return array<string, array{bool, mixed}> present, value */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (self::KEYS as $key) {
            $snapshot[$key] = [$this->context->has($key), $this->context->get($key)];
        }

        return $snapshot;
    }

    /** @param array<string, string> $metadata */
    private function apply(array $metadata): void
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $metadata)) {
                $this->context->add($key, $metadata[$key]);
            } else {
                $this->context->forget($key);
            }
        }
    }

    /** @param array<string, array{bool, mixed}> $snapshot */
    private function restore(array $snapshot): void
    {
        foreach ($snapshot as $key => [$present, $value]) {
            if ($present) {
                $this->context->add($key, $value);
            } else {
                $this->context->forget($key);
            }
        }
    }
}
