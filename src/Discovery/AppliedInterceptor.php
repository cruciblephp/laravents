<?php

declare(strict_types=1);

namespace Laravents\Discovery;

/**
 * An interceptor as it applies to one handler: the definition, and the pointcut attribute found on the
 * handler method or class when the pointcut is an attribute (null when it matched by type).
 */
final readonly class AppliedInterceptor
{
    public function __construct(
        public InterceptorDefinition $interceptor,
        public ?object $attribute,
    ) {}
}
