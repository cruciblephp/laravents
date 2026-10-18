<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Support\Str;
use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\Deferred;
use Laravents\Attributes\Expose;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Interceptors\AuthorizationInterceptor;
use Laravents\Interceptors\TransactionInterceptor;
use Laravents\Invocation\MethodInvocation;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reads handler, interceptor and #[Publishes] attributes from classes and enforces the discovery rules, so mistakes fail at boot.
 */
final readonly class RegistryBuilder
{
    /** Built-in interceptors, registered like any other and always present. */
    public const array BUILT_IN = [AuthorizationInterceptor::class, TransactionInterceptor::class];

    public function __construct(private string $rootNamespace) {}

    /** @param iterable<class-string> $classes */
    public function build(iterable $classes): Registry
    {
        $handlers = [];
        $interceptors = [];
        $publications = [];
        $all = self::BUILT_IN;

        foreach ($classes as $class) {
            if (! in_array($class, $all, true)) {
                $all[] = $class;
            }
        }

        foreach ($all as $class) {
            $reflection = new ReflectionClass($class);
            array_push($publications, ...ModelPublications::read($reflection));

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $handler = $this->definition($reflection, $method);
                $interceptor = $this->interceptor($reflection, $method);

                if ($handler instanceof HandlerDefinition && $interceptor instanceof InterceptorDefinition) {
                    throw DiscoveryException::invalid($interceptor->location(), 'is both a handler and an interceptor');
                }

                if ($handler instanceof HandlerDefinition) {
                    $handlers[] = $handler;
                }

                if ($interceptor instanceof InterceptorDefinition) {
                    $interceptors[] = $interceptor;
                }
            }
        }

        return $this->index($handlers, $interceptors, $publications);
    }

    /** @param ReflectionClass<object> $class */
    private function interceptor(ReflectionClass $class, ReflectionMethod $method): ?InterceptorDefinition
    {
        $location = $class->getName().'@'.$method->getName().' ('.$class->getFileName().':'.$method->getStartLine().')';
        $found = [];

        foreach (InterceptorKind::cases() as $kind) {
            foreach ($method->getAttributes($kind->attribute()) as $attribute) {
                $found[] = [$kind, $attribute->newInstance()];
            }
        }

        if ($found === []) {
            return null;
        }

        if (count($found) > 1) {
            throw DiscoveryException::invalid($location, 'has more than one of #[Before], #[Around] and #[After]');
        }

        [$kind, $attribute] = $found[0];

        if (! $method->isPublic() || $method->isStatic()) {
            throw DiscoveryException::invalid($location, 'must be a public, non-static method');
        }

        if (! class_exists($attribute->pointcut) && ! interface_exists($attribute->pointcut)) {
            throw DiscoveryException::invalid($location, sprintf('has an unknown pointcut [%s]', $attribute->pointcut));
        }

        if ($kind === InterceptorKind::Around && ! $this->takes($method, MethodInvocation::class)) {
            throw DiscoveryException::invalid($location, 'is an #[Around] without a MethodInvocation parameter');
        }

        return new InterceptorDefinition(
            kind: $kind,
            pointcut: $attribute->pointcut,
            precedence: $attribute->precedence,
            class: $class->getName(),
            method: $method->getName(),
            file: (string) $class->getFileName(),
            line: (int) $method->getStartLine(),
        );
    }

    /** @param class-string $class */
    private function takes(ReflectionMethod $method, string $class): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pointcut applied to a handler, or null when it does not apply. It applies when the handler method or
     * class carries the pointcut attribute (that instance is kept), or when the handler class is the pointcut type.
     */
    private function apply(InterceptorDefinition $interceptor, HandlerDefinition $handler): ?AppliedInterceptor
    {
        $attribute = (new ReflectionMethod($handler->class, $handler->method))->getAttributes($interceptor->pointcut, ReflectionAttribute::IS_INSTANCEOF)[0]
            ?? (new ReflectionClass($handler->class))->getAttributes($interceptor->pointcut, ReflectionAttribute::IS_INSTANCEOF)[0]
            ?? null;

        if ($attribute !== null) {
            return new AppliedInterceptor($interceptor, $attribute->newInstance());
        }

        return is_a($handler->class, $interceptor->pointcut, true) ? new AppliedInterceptor($interceptor, null) : null;
    }

    /** @param ReflectionClass<object> $class */
    private function definition(ReflectionClass $class, ReflectionMethod $method): ?HandlerDefinition
    {
        $location = $class->getName().'@'.$method->getName().' ('.$class->getFileName().':'.$method->getStartLine().')';
        $found = [];

        foreach (HandlerKind::cases() as $kind) {
            foreach ($method->getAttributes($kind->attribute()) as $attribute) {
                $found[] = [$kind, $attribute->newInstance()];
            }
        }

        if ($found === []) {
            return null;
        }

        if (count($found) > 1) {
            throw DiscoveryException::invalid($location, 'has more than one of #[CommandHandler], #[QueryHandler] and #[EventHandler]');
        }

        [$kind, $attribute] = $found[0];

        if (! $method->isPublic() || $method->isStatic()) {
            throw DiscoveryException::invalid($location, 'must be a public, non-static method');
        }

        $asynchronous = $method->getAttributes(Asynchronous::class)[0] ?? null;

        if ($kind === HandlerKind::Event && $method->getAttributes(Authorize::class) !== []) {
            throw DiscoveryException::invalid($location, 'has #[Authorize], but #[Authorize] is not allowed on an event handler: events are facts and cannot be refused');
        }

        $exposed = $method->getAttributes(Expose::class) !== [];

        if ($kind === HandlerKind::Event && $exposed) {
            throw DiscoveryException::invalid($location, 'has #[Expose], but #[Expose] is only allowed on a command or query handler: events are not requested');
        }

        if ($kind !== HandlerKind::Event && $asynchronous !== null) {
            throw DiscoveryException::invalid($location, 'has #[Asynchronous], but #[Asynchronous] is only allowed on an event handler');
        }

        $deferred = $method->getAttributes(Deferred::class) !== [];

        if ($kind !== HandlerKind::Event && $deferred) {
            throw DiscoveryException::invalid($location, 'has #[Deferred], but #[Deferred] is only allowed on an event handler');
        }

        if ($deferred && $asynchronous !== null) {
            throw DiscoveryException::invalid($location, 'has both #[Deferred] and #[Asynchronous], but a handler runs in one mode: after the response or on a queue');
        }

        $messageClass = $this->messageClass($method);
        $routingKey = $attribute->routingKey;
        $pattern = $this->pattern($kind, $routingKey, $messageClass, $location);

        if ($routingKey === null && $messageClass === null) {
            throw DiscoveryException::invalid($location, 'needs a routing key or a class or interface as its first parameter');
        }

        if ($routingKey === null && $kind !== HandlerKind::Event) {
            $routingKey = RoutingKey::derive((string) $messageClass, $this->rootNamespace)
                ?? throw DiscoveryException::invalid($location, sprintf(
                    'cannot derive a routing key from [%s] because it is not in a module under [%s]; pass the key explicitly',
                    $messageClass,
                    $this->rootNamespace,
                ));
        }

        $module = RoutingKey::module($class->getName(), $this->rootNamespace);
        $queue = null;
        $mode = $deferred ? HandlerMode::Deferred : HandlerMode::Sync;

        if ($asynchronous !== null) {
            $mode = HandlerMode::Async;
            $queue = $asynchronous->newInstance()->channel ?? ($module !== null ? Str::snake($module) : 'default');
        }

        return new HandlerDefinition(
            kind: $kind,
            routingKey: $routingKey,
            messageClass: $messageClass,
            class: $class->getName(),
            method: $method->getName(),
            module: $module,
            mode: $mode,
            queue: $queue,
            exposed: $exposed,
            file: (string) $class->getFileName(),
            line: (int) $method->getStartLine(),
            pattern: $pattern,
        );
    }

    /**
     * The compiled routing-key pattern of an event handler, null for a plain key; refuses a pattern that is not
     * `*` as whole segments beside at least one literal one, a pattern on a command or query handler, and a pattern
     * handler that names a class: a pattern covers keyed and object events alike, so it takes the payload as published.
     */
    private function pattern(HandlerKind $kind, ?string $routingKey, ?string $messageClass, string $location): ?string
    {
        if ($routingKey === null || ! KeyPattern::isPattern($routingKey)) {
            return null;
        }

        if ($kind !== HandlerKind::Event) {
            throw DiscoveryException::invalid($location, sprintf('has the routing-key pattern [%s], but a pattern is only allowed on an event handler: a command or query has exactly one handler', $routingKey));
        }

        $refusal = KeyPattern::refusal($routingKey);

        if ($refusal !== null) {
            throw DiscoveryException::invalid($location, $refusal);
        }

        if ($messageClass !== null) {
            throw DiscoveryException::invalid($location, sprintf(
                'has the routing-key pattern [%s] and takes [%s], but a pattern handler takes the payload as published (object|array or mixed): a pattern covers keyed and object events alike',
                $routingKey,
                $messageClass,
            ));
        }

        return KeyPattern::compile($routingKey);
    }

    /** @return class-string|null */
    private function messageClass(ReflectionMethod $method): ?string
    {
        $type = ($method->getParameters()[0] ?? null)?->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return class_exists($name) || interface_exists($name) ? $name : null;
    }

    /**
     * @param  list<HandlerDefinition>  $handlers
     * @param  list<InterceptorDefinition>  $interceptors
     * @param  list<ModelPublication>  $publications
     */
    private function index(array $handlers, array $interceptors, array $publications): Registry
    {
        $byKey = [HandlerKind::Command->value => [], HandlerKind::Query->value => []];
        $byMessage = [HandlerKind::Command->value => [], HandlerKind::Query->value => []];
        $keysByMessage = [HandlerKind::Command->value => [], HandlerKind::Query->value => []];
        $typedEvents = [];
        $keyedEvents = [];
        $patternEvents = [];
        $external = [];
        $prefix = trim($this->rootNamespace, '\\').'\\';

        foreach ($handlers as $handler) {
            if ($handler->kind === HandlerKind::Event) {
                if ($handler->isPattern()) {
                    $patternEvents[] = $handler;
                } elseif ($handler->routingKey !== null) {
                    $keyedEvents[$handler->routingKey][] = $handler;
                } else {
                    $typedEvents[] = $handler;

                    if ($handler->messageClass !== null && ! str_starts_with($handler->messageClass, $prefix) && ! in_array($handler->messageClass, $external, true)) {
                        $external[] = $handler->messageClass;
                    }
                }

                continue;
            }

            $kind = $handler->kind->value;
            $key = (string) $handler->routingKey;

            if (isset($byKey[$kind][$key])) {
                throw DiscoveryException::duplicate(sprintf('%s routing key [%s]', $handler->kind->label(), $key), $byKey[$kind][$key], $handler);
            }

            $byKey[$kind][$key] = $handler;

            if ($handler->messageClass !== null) {
                if (isset($byMessage[$kind][$handler->messageClass])) {
                    throw DiscoveryException::duplicate(sprintf('%s message [%s]', $handler->kind->label(), $handler->messageClass), $byMessage[$kind][$handler->messageClass], $handler);
                }

                $byMessage[$kind][$handler->messageClass] = $handler;
                $keysByMessage[$kind][$handler->messageClass] = $key;
            }
        }

        $byPrecedence = $interceptors;
        usort($byPrecedence, static fn (InterceptorDefinition $a, InterceptorDefinition $b): int => $a->precedence <=> $b->precedence);
        $interceptorsByHandler = [];

        foreach ($handlers as $handler) {
            $interceptorsByHandler[$handler->handler()] = array_values(array_filter(array_map(
                fn (InterceptorDefinition $interceptor): ?AppliedInterceptor => $this->apply($interceptor, $handler),
                $byPrecedence,
            )));
        }

        return new Registry(
            handlers: $handlers,
            commands: $byKey[HandlerKind::Command->value],
            queries: $byKey[HandlerKind::Query->value],
            keysByMessage: $keysByMessage,
            typedEventHandlers: $typedEvents,
            keyedEventHandlers: $keyedEvents,
            interceptors: $interceptors,
            interceptorsByHandler: $interceptorsByHandler,
            externalEventClasses: $external,
            modelPublications: $publications,
            patternEventHandlers: $patternEvents,
            rootNamespace: $this->rootNamespace,
        );
    }
}
