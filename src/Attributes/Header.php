<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Injects a message header, such as actor_id or correlation_id, into a handler or interceptor parameter.
 * #[Header] reads the message; Laravel's own contextual attributes (#[CurrentUser], #[Config], #[Context], ...) read the app.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Header
{
    public function __construct(public string $name) {}
}
