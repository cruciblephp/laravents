<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Closure;
use UnexpectedValueException;

/**
 * Every handler, indexed the way the buses look them up. The one place that matches an event to its handlers: by
 * type, by key and by key pattern.
 *
 * Held by index: one list of handlers, and every lookup table points into it. A registry the builder made holds the
 * definitions themselves. One read back from the cache (fromArray()) holds them as plain rows and builds a definition
 * the first time it is looked up, so a request that dispatches one command builds one handler, not all of them.
 *
 * @phpstan-type HandlerRow array{string, string|null, class-string|null, class-string, string, string|null, string, string|null, bool, string, int, string|null}
 * @phpstan-type InterceptorRow array{string, class-string, int, class-string, string, string, int}
 * @phpstan-type PublicationRow array{class-string<\Illuminate\Database\Eloquent\Model>, string, class-string, bool, string, int}
 * @phpstan-type ModuleRow array{string, string, bool, array{class-string, array<string, mixed>}|null}
 * @phpstan-type AppliedRow array{int, array{class-string, array<array-key, mixed>}|bool}
 * @phpstan-type RegistryData array{handlers: list<HandlerRow>, commands: array<string, int>, queries: array<string, int>, keysByMessage: array<value-of<HandlerKind>, array<class-string, string>>, typedEventHandlers: list<int>, keyedEventHandlers: array<string, list<int>>, interceptors: list<InterceptorRow>, interceptorsByHandler: array<string, list<AppliedRow>>, externalEventClasses: list<class-string>, modelPublications: list<PublicationRow>, patternEventHandlers: list<int>, rootNamespace: string|null, modules: list<ModuleRow>}
 */
final class Registry
{
    /** The lists toArray() writes, every one of which a cache file must hold to be read back. */
    public const array LISTS = ['handlers', 'commands', 'queries', 'keysByMessage', 'typedEventHandlers', 'keyedEventHandlers', 'interceptors', 'interceptorsByHandler', 'externalEventClasses', 'modelPublications', 'patternEventHandlers', 'modules'];

    /** @var array<string, list<HandlerDefinition>> Typed handlers by event class, resolved on first use */
    private array $resolvedEventHandlers = [];

    /** @var array<string, list<HandlerDefinition>> Exact then pattern handlers by routing key, resolved on first use */
    private array $resolvedKeyHandlers = [];

    /** @var array<string, list<HandlerDefinition>> Typed then pattern handlers by event class, resolved on first use */
    private array $resolvedObjectHandlers = [];

    /** @var array<string, int>|null Handler index by Class@method, built on first use */
    private ?array $handlersByName = null;

    /** @var array<int, HandlerDefinition|HandlerRow> In discovery order: a definition, or its row until first looked up */
    private array $handlers = [];

    /** @var array<string, int> */
    private array $commands;

    /** @var array<string, int> */
    private array $queries;

    /** @var list<int> */
    private array $typedEventHandlers;

    /** @var array<string, list<int>> */
    private array $keyedEventHandlers;

    /** @var list<int> */
    private array $patternEventHandlers;

    /** @var (Closure(class-string, array<string, mixed>): object)|null Rebuilds a cached module manifest */
    private ?Closure $manifests = null;

    /** Read back from the cache (fromArray()) rather than built by a scan */
    private bool $fromCache = false;

    /**
     * @param  list<HandlerDefinition>  $handlers  In discovery order
     * @param  array<string, HandlerDefinition>  $commands  By routing key
     * @param  array<string, HandlerDefinition>  $queries  By routing key
     * @param  array<value-of<HandlerKind>, array<class-string, string>>  $keysByMessage
     * @param  list<HandlerDefinition>  $typedEventHandlers  Unkeyed, matched by type
     * @param  array<string, list<HandlerDefinition>>  $keyedEventHandlers  By routing key
     * @param  list<InterceptorDefinition>  $interceptors  In discovery order
     * @param  array<string, list<AppliedInterceptor>|list<AppliedRow>>  $interceptorsByHandler  By Class@method, in precedence order; read back from the cache, [interceptor index, attribute] rows until the handler is first looked up: the attribute's class and plain arguments, true for one only reflection rebuilds, false for none
     * @param  list<class-string>  $externalEventClasses  Unkeyed event handlers' classes outside the modules namespace
     * @param  list<ModelPublication>  $modelPublications  #[Publishes] on models, in discovery order
     * @param  list<HandlerDefinition>  $patternEventHandlers  #[EventHandler('band.*')], in discovery order
     * @param  string|null  $rootNamespace  The modules namespace, which an event object's derived key comes from
     * @param  array<string, Module|ModuleRow>  $modules  Every module by name, sorted, the disabled ones included (Module::discover()); read back from the cache, rows until first looked up
     */
    public function __construct(
        array $handlers,
        array $commands,
        array $queries,
        private readonly array $keysByMessage,
        array $typedEventHandlers,
        array $keyedEventHandlers,
        private readonly array $interceptors = [],
        private array $interceptorsByHandler = [],
        private readonly array $externalEventClasses = [],
        private readonly array $modelPublications = [],
        array $patternEventHandlers = [],
        private readonly ?string $rootNamespace = null,
        private array $modules = [],
    ) {
        $index = [];

        foreach ($handlers as $n => $handler) {
            $this->handlers[$n] = $handler;
            $index[spl_object_id($handler)] = $n;
        }

        $at = static fn (HandlerDefinition $handler): int => $index[spl_object_id($handler)]
            ?? throw new UnexpectedValueException(sprintf('%s is looked up but not among the registry\'s handlers.', $handler->handler()));

        $this->commands = array_map($at, $commands);
        $this->queries = array_map($at, $queries);
        $this->typedEventHandlers = array_map($at, $typedEventHandlers);
        $this->keyedEventHandlers = array_map(static fn (array $list): array => array_map($at, $list), $keyedEventHandlers);
        $this->patternEventHandlers = array_map($at, $patternEventHandlers);
    }

    /**
     * The registry as plain data, for the cache: only strings, ints, bools, null and arrays, which var_export()
     * writes as PHP and opcache keeps as it is. A definition is a row in its constructor's order. A pointcut
     * attribute is written as its class and the arguments in the source, built with `new` when the handler is first
     * looked up; one with an argument that is not plain data (HandlerDefinition::plainAttribute()) is read again
     * through reflection then. A module manifest is written as its public properties, the constructor
     * parameters the manifest class takes.
     *
     * @return RegistryData
     */
    public function toArray(): array
    {
        $interceptorIndex = [];

        foreach ($this->interceptors as $n => $interceptor) {
            $interceptorIndex[spl_object_id($interceptor)] = $n;
        }

        $applied = [];

        foreach ($this->interceptorsByHandler as $name => $list) {
            $handler = $this->handler($name);
            // The attribute as its class and plain arguments; true when only reflection rebuilds it.
            $applied[$name] = array_map(static fn (AppliedInterceptor|array $a): array => is_array($a) ? $a : [
                $interceptorIndex[spl_object_id($a->interceptor)],
                $a->attribute === null ? false : ($handler?->plainAttribute($a->interceptor->pointcut) ?: true),
            ], $list);
        }

        return [
            'handlers' => array_map(fn (int $n): array => $this->handlerRow($this->definition($n)), array_keys($this->handlers)),
            'commands' => $this->commands,
            'queries' => $this->queries,
            'keysByMessage' => $this->keysByMessage,
            'typedEventHandlers' => $this->typedEventHandlers,
            'keyedEventHandlers' => $this->keyedEventHandlers,
            'interceptors' => array_map(static fn (InterceptorDefinition $i): array => [
                $i->kind->value, $i->pointcut, $i->precedence, $i->class, $i->method, $i->file, $i->line,
            ], $this->interceptors),
            'interceptorsByHandler' => $applied,
            'externalEventClasses' => $this->externalEventClasses,
            'modelPublications' => array_map(static fn (ModelPublication $p): array => [
                $p->model, $p->hook, $p->event, $p->fromModel, $p->file, $p->line,
            ], $this->modelPublications),
            'patternEventHandlers' => $this->patternEventHandlers,
            'rootNamespace' => $this->rootNamespace,
            'modules' => array_map(fn (string $name): array => $this->moduleRow($this->module($name) ?? throw new UnexpectedValueException("Module [{$name}] is listed but cannot be built.")), array_map(strval(...), array_keys($this->modules))),
        ];
    }

    /**
     * A registry from toArray()'s data, building each definition when it is first looked up. $manifests rebuilds a
     * module manifest from its class and fields, as discovery built it (the payload converter).
     *
     * @param  RegistryData  $data
     * @param  Closure(class-string, array<string, mixed>): object  $manifests
     */
    public static function fromArray(array $data, Closure $manifests): self
    {
        $registry = new self([], [], [], $data['keysByMessage'], [], [],
            array_map(static fn (array $r): InterceptorDefinition => new InterceptorDefinition(
                InterceptorKind::from($r[0]), $r[1], $r[2], $r[3], $r[4], $r[5], $r[6],
            ), $data['interceptors']),
            [],
            $data['externalEventClasses'],
            array_map(static fn (array $r): ModelPublication => new ModelPublication(...$r), $data['modelPublications']),
            [],
            $data['rootNamespace'],
        );

        $registry->handlers = $data['handlers'];
        $registry->commands = $data['commands'];
        $registry->queries = $data['queries'];
        $registry->typedEventHandlers = $data['typedEventHandlers'];
        $registry->keyedEventHandlers = $data['keyedEventHandlers'];
        $registry->patternEventHandlers = $data['patternEventHandlers'];
        $registry->interceptorsByHandler = $data['interceptorsByHandler'];

        foreach ($data['modules'] as $row) {
            $registry->modules[$row[0]] = $row;
        }

        $registry->manifests = $manifests;
        $registry->fromCache = true;

        return $registry;
    }

    /** Whether this registry was read back from the cache, not scanned: what laravents:list, laravents:show and about report. */
    public function fromCache(): bool
    {
        return $this->fromCache;
    }

    /**
     * Every module by name, sorted, the disabled ones included: what Laravents::modules() answers.
     *
     * @return array<string, Module>
     */
    public function modules(): array
    {
        $modules = [];

        foreach (array_keys($this->modules) as $name) {
            $modules[$name] = $this->module((string) $name) ?? throw new UnexpectedValueException("Module [{$name}] is listed but cannot be built.");
        }

        return $modules;
    }

    /** One module by name, or null when there is no such module: what Laravents::module() answers. */
    public function module(string $name): ?Module
    {
        $module = $this->modules[$name] ?? null;

        if (! is_array($module)) {
            return $module;
        }

        [, $namespace, $disabled, $manifest] = $module;

        return $this->modules[$name] = new Module($name, $namespace, $disabled, $manifest === null || ! $this->manifests instanceof Closure
            ? null
            : ($this->manifests)($manifest[0], $manifest[1]));
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
        $name = $handler->handler();
        $applied = $this->interceptorsByHandler[$name] ?? [];

        // A handler's list is all rows (read from the cache) until its first lookup, then all instances.
        if ($applied === [] || $applied[0] instanceof AppliedInterceptor) {
            /** @var list<AppliedInterceptor> $applied */
            return $applied;
        }

        /** @var list<AppliedRow> $applied */
        return $this->interceptorsByHandler[$name] = array_map(function (array $row) use ($handler): AppliedInterceptor {
            $interceptor = $this->interceptors[$row[0]];

            $attribute = match (true) {
                is_array($row[1]) => new $row[1][0](...$row[1][1]),
                $row[1] => $handler->attribute($interceptor->pointcut),
                default => null,
            };

            return new AppliedInterceptor($interceptor, $attribute);
        }, $applied);
    }

    /** @return list<HandlerDefinition> */
    public function all(): array
    {
        return array_map($this->definition(...), array_keys($this->handlers));
    }

    /**
     * The #[Expose]d command or query handlers by routing key, sorted: what the HTTP gateway serves, for an adapter of
     * the application's own to list. An event handler is never exposed, so none for events.
     *
     * @return array<string, HandlerDefinition>
     */
    public function exposed(HandlerKind $kind): array
    {
        $exposed = array_filter(array_map($this->definition(...), match ($kind) {
            HandlerKind::Command => $this->commands,
            HandlerKind::Query => $this->queries,
            HandlerKind::Event => [],
        }), static fn (HandlerDefinition $handler): bool => $handler->exposed);

        ksort($exposed);

        return $exposed;
    }

    /** A handler by its Class@method, the name a queued job carries. */
    public function handler(string $handler): ?HandlerDefinition
    {
        if ($this->handlersByName === null) {
            $this->handlersByName = [];

            foreach ($this->handlers as $n => $definition) {
                $this->handlersByName[$definition instanceof HandlerDefinition ? $definition->handler() : $definition[3].'@'.$definition[4]] = $n;
            }
        }

        $n = $this->handlersByName[$handler] ?? null;

        return $n === null ? null : $this->definition($n);
    }

    public function commandHandler(string $routingKey): ?HandlerDefinition
    {
        $n = $this->commands[$routingKey] ?? null;

        return $n === null ? null : $this->definition($n);
    }

    public function queryHandler(string $routingKey): ?HandlerDefinition
    {
        $n = $this->queries[$routingKey] ?? null;

        return $n === null ? null : $this->definition($n);
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
            array_map($this->definition(...), $this->typedEventHandlers),
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
            ...array_map($this->definition(...), $this->keyedEventHandlers[$routingKey] ?? []),
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
            array_map($this->definition(...), $this->patternEventHandlers),
            static fn (HandlerDefinition $handler): bool => KeyPattern::matches((string) $handler->pattern, $routingKey),
        ));
    }

    /** The handler at index $n, built from its row the first time. */
    private function definition(int $n): HandlerDefinition
    {
        $handler = $this->handlers[$n];

        if ($handler instanceof HandlerDefinition) {
            return $handler;
        }

        return $this->handlers[$n] = new HandlerDefinition(
            HandlerKind::from($handler[0]), $handler[1], $handler[2], $handler[3], $handler[4], $handler[5],
            HandlerMode::from($handler[6]), $handler[7], $handler[8], $handler[9], $handler[10], $handler[11],
        );
    }

    /** @return HandlerRow */
    private function handlerRow(HandlerDefinition $h): array
    {
        return [
            $h->kind->value, $h->routingKey, $h->messageClass, $h->class, $h->method, $h->module,
            $h->mode->value, $h->queue, $h->exposed, $h->file, $h->line, $h->pattern,
        ];
    }

    /** @return ModuleRow */
    private function moduleRow(Module $module): array
    {
        /** @var array<string, mixed> $fields */
        $fields = $module->manifest === null ? [] : get_object_vars($module->manifest);

        return [
            $module->name,
            $module->namespace,
            $module->disabled,
            $module->manifest === null ? null : [$module->manifest::class, $fields],
        ];
    }
}
