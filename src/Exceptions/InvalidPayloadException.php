<?php

declare(strict_types=1);

namespace Laravents\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * An event whose outbox payload cannot be stored: the serialized event is not valid UTF-8, so it cannot be
 * JSON-encoded, as Laravel's queue refuses a job in createPayload(). Thrown inside the publisher's
 * transaction, so nothing is half written.
 */
final class InvalidPayloadException extends InvalidArgumentException
{
    public static function for(string $event, string $listener, Throwable $previous): self
    {
        return new self(sprintf(
            'Unable to JSON encode the payload of event [%s] for listener [%s]: %s. A binary property must be encoded by the event (base64) before publishing, as a job\'s must.',
            $event,
            $listener,
            $previous->getMessage(),
        ), previous: $previous);
    }
}
