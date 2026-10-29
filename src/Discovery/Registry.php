<?php

declare(strict_types=1);

namespace Laravents\Discovery;

/**
 * Every handler, indexed the way the buses look them up. The one place that matches an event to its handlers: by
 * type, by key and by key pattern.
 */
final class Registry
{
    /** @var array<string, list<HandlerDefinition>> Typed handlers by event class, resolved on first use */
    private array $resolvedEventHandlers = [];

    /** @var array<string, list<HandlerDefinition>> Exact then pattern handlers by routing key, resolved on first use */
    private array $resolvedKeyHandlers = [];

    /** @var array<string, list<HandlerDefinition>> Typed then pattern handlers by event class, resolved on first use */
    private array $resolvedObjectHandlers = [];

    /** @var array<string, HandlerDefinition>|null By Class@method, built on first use */
    private ?array $handlersByName = null;

    /**
     * @param  list<HandlerDefinition>  $handlers  In discovery order
     * @param  array<string, HandlerDefinition>  $commands  By routing key
     * @param  array<string, HandlerDefinition>  $queries  By routing key
     * @param  array<value-of<HandlerKind>, array<class-string, string>>  $keysByMessage
     * @param  list<HandlerDefinition>  $typedEventHandlers  Unkeyed, matched by type
     * @param  array<string, list<HandlerDefinition>>  $keyedEventHandlers  By routing key
     * @param  list<InterceptorDefinition>  $interceptors  In discovery order
     * @param  array<string, list<AppliedInterceptor>>  $interceptorsByHandler  By Class@method, in precedence order
     * @param  list<class-string>  $externalEventClasses  Unkeyed event handlers' classes outside the modules namespace
     * @param  list<ModelPublication>  $modelPublications  #[Publishes] on models, in discovery order
     * @param  list<HandlerDefinition>  $patternEventHandlers  #[EventHandler('band.*')], in discovery order
     * @param  string|null  $rootNamespace  The modules namespace, which an event object's derived key comes from
     * @param  array<string, Module>  $modules  Every module by name, sorted, the disabled ones included (Module::discover())
     */
    public function __construct(
        private readonly array $handlers,
        private readonly array $commands,
        private readonly array $queries,
        private readonly array $keysByMessage,
        private readonly array $typedEventHandlers,
        private readonly array $keyedEventHandlers,
        private readonly array $interceptors = [],
        private readonly array $interceptorsByHandler = [],
        private readonly array $externalEventClasses = [],
        private readonly array $modelPublications = [],
        private readonly array $patternEventHandlers = [],
        private readonly ?string $rootNamespace = null,
        private readonly array $modules = [],
    ) {}

    /**
     * Every module by name, sorted, the disabled ones included: what Laravents::modules() answers.
     *
     * @return array<string, Module>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /** One module by name, or null when there is no such module: what Laravents::module() answers. */
    public function module(string $name): ?Module
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * Every #[Publishes] on a discovered model: which Eloquent event of which model publishes which event.
     *
     * @return list<ModelPublication>
     */
    public function modelPublications(): array
    {
        return $this->modelPublications;
    }

    /**
     * The framework, package and application event classes (outside the modules namespace) an unkeyed
     * #[EventHandler] takes, in discovery order, each once: what Laravel's dispatcher hands to Laravents.
     *
     * @return list<class-string>
     */
    public function externalEventClasses(): array
    {
        return $this->externalEventClasses;
    }

    /** @return list<InterceptorDefinition> */
    public function interceptors(): array
    {
        return $this->interceptors;
    }

    /**
     * The interceptors whose pointcut matches the handler, lowest precedence (outermost) first, each with
     * the pointcut attribute instance it matched by.
     *
     * @return list<AppliedInterceptor>
     */
    public function interceptorsFor(HandlerDefinition $handler): array
    {
        return $this->interceptorsByHandler[$handler->handler()] ?? [];
    }

    /** @return list<HandlerDefinition> */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * The #[Expose]d command or query handlers by routing key, sorted: what the HTTP gateway serves, for an adapter of
     * the application's own to list. An event handler is never exposed, so none for events.
     *
     * @return array<string, HandlerDefinition>
     */
    public function exposed(HandlerKind $kind): array
    {
        $exposed = array_filter(match ($kind) {
            HandlerKind::Command => $this->commands,
            HandlerKind::Query => $this->queries,
            HandlerKind::Event => [],
        }, static fn (HandlerDefinition $handler): bool => $handler->exposed);

        ksort($exposed);

        return $exposed;
    }

    /** A handler by its Class@method, the name a queued job carries. */
    public function handler(string $handler): ?HandlerDefinition
    {
        if ($this->handlersByName === null) {
            $this->handlersByName = [];

            foreach ($this->handlers as $definition) {
                $this->handlersByName[$definition->handler()] = $definition;
            }
        }

        return $this->handlersByName[$handler] ?? null;
    }

    public function commandHandler(string $routingKey): ?HandlerDefinition
    {
        return $this->commands[$routingKey] ?? null;
    }

    public function queryHandler(string $routingKey): ?HandlerDefinition
    {
        return $this->queries[$routingKey] ?? null;
    }

    /** The one handler of a command or query key; events have many (eventHandlersForKey()), so null. */
    public function handlerFor(HandlerKind $kind, string $routingKey): ?HandlerDefinition
    {
        return match ($kind) {
            HandlerKind::Command => $this->commandHandler($routingKey),
            HandlerKind::Query => $this->queryHandler($routingKey),
            HandlerKind::Event => null,
        };
    }

    /**
     * The routing keys registered for one kind: commands and queries by their handler, events by #[EventHandler('key')]
     * (exact keys only; a pattern is not a key).
     *
     * @return list<string>
     */
    public function routingKeys(HandlerKind $kind): array
    {
        return array_map(strval(...), array_keys(match ($kind) {
            HandlerKind::Command => $this->commands,
            HandlerKind::Query => $this->queries,
            HandlerKind::Event => $this->keyedEventHandlers,
        }));
    }

    /**
     * A command's or query's key by its handler; an event's key derived from its class, as a command's is
     * (Modules\Band\Api\NicknameGiven is band.nickname_given), null for a class outside a module (a framework event).
     *
     * @param  class-string  $messageClass
     */
    public function routingKeyFor(string $messageClass, HandlerKind $kind): ?string
    {
        if ($kind === HandlerKind::Event) {
            return $this->rootNamespace === null ? null : RoutingKey::derive($messageClass, $this->rootNamespace);
        }

        return $this->keysByMessage[$kind->value][$messageClass] ?? null;
    }

    /**
     * Unkeyed event handlers whose parameter type the event is an instance of, in discovery order.
     *
     * @param  object|class-string  $event
     * @return list<HandlerDefinition>
     */
    public function eventHandlersFor(object|string $event): array
    {
        $class = is_object($event) ? $event::class : $event;

        return $this->resolvedEventHandlers[$class] ??= array_values(array_filter(
            $this->typedEventHandlers,
            static fn (HandlerDefinition $handler): bool => $handler->messageClass !== null && is_a($class, $handler->messageClass, true),
        ));
    }

    /**
     * What a keyed publish runs: the handlers of exactly this key, then the pattern handlers whose pattern matches it,
     * each group in discovery order. Resolved once per key for the life of the process. A handler has one
     * #[EventHandler], so it is in one group and listed once.
     *
     * @return list<HandlerDefinition>
     */
    public function eventHandlersForKey(string $routingKey): array
    {
        return $this->resolvedKeyHandlers[$routingKey] ??= [
            ...$this->keyedEventHandlers[$routingKey] ?? [],
            ...$this->patternHandlersFor($routingKey),
        ];
    }

    /**
     * What an object publish runs: the typed handlers (eventHandlersFor()), then the pattern handlers whose pattern
     * matches the event's derived key (routingKeyFor()), each group in discovery order. A class outside a module (a
     * framework event) has no key, so no pattern reaches it. Resolved once per class for the life of the process.
     *
     * @param  object|class-string  $event
     * @return list<HandlerDefinition>
     */
    public function eventHandlersForObject(object|string $event): array
    {
        $class = is_object($event) ? $event::class : $event;

        if (isset($this->resolvedObjectHandlers[$class])) {
            return $this->resolvedObjectHandlers[$class];
        }

        $key = $this->routingKeyFor($class, HandlerKind::Event);

        return $this->resolvedObjectHandlers[$class] = [
            ...$this->eventHandlersFor($class),
            ...$key === null ? [] : $this->patternHandlersFor($key),
        ];
    }

    /**
     * The pattern handlers whose pattern covers the key, in discovery order.
     *
     * @return list<HandlerDefinition>
     */
    public function patternHandlersFor(string $routingKey): array
    {
        return array_values(array_filter(
            $this->patternEventHandlers,
            static fn (HandlerDefinition $handler): bool => KeyPattern::matches((string) $handler->pattern, $routingKey),
        ));
    }
}
