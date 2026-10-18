<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Metadata;

use Illuminate\Support\Facades\Context;

/**
 * What a fixture handler saw: the ids injected as headers, and the ids in Context at that moment.
 */
final readonly class Ids
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $messageId,
        public string $correlationId,
        public ?string $causationId,
        public array $context,
    ) {}

    public static function capture(string $messageId, string $correlationId, ?string $causationId): self
    {
        $context = [];

        foreach (['message_id', 'correlation_id', 'causation_id'] as $key) {
            if (Context::has($key)) {
                $context[$key] = Context::get($key);
            }
        }

        return new self($messageId, $correlationId, $causationId, $context);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return array_filter(
            ['message_id' => $this->messageId, 'correlation_id' => $this->correlationId, 'causation_id' => $this->causationId],
            static fn (?string $value): bool => $value !== null,
        );
    }
}
