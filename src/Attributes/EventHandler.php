<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Marks one of zero to many handlers of an event. Without a key, it matches events that are instances of its first parameter's type.
 * With a key, that key. With a pattern such as `band.*` (`*` is one whole dotted segment, the only operator), every key the
 * pattern covers: keyed publishes, and module events published as objects by their derived key (NicknameGiven is band.nickname_given).
 * A native Laravel event has no key, so no pattern reaches it. A pattern handler takes the payload as published.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class EventHandler
{
    public function __construct(public ?string $routingKey = null) {}
}
