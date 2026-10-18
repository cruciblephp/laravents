<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Runs an event handler after the response, in the same process, through Laravel's defer(): after a successful
 * response (a 4xx or 5xx skips it), at the end of an Artisan command that exits 0, or after a queued job that
 * succeeded. The handler sees the event as published, headers and all, with its interceptors, as the user still
 * on the guard. Inside a transaction it is registered when the outermost one commits, so a rollback drops it.
 *
 * Best effort, which is the point of the mode: no outbox row and no retry. An exception is reported through the
 * exception handler and is lost; the response has already been sent. Use #[Asynchronous] for work that must happen.
 * Only on an event handler, and not together with #[Asynchronous].
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Deferred {}
