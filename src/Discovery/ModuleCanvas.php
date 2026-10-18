<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Contracts\Config\Repository;
use Laravents\Concerns\Askable;
use Laravents\Concerns\Publishable;
use Laravents\Concerns\Sendable;
use Laravents\Http\Endpoints;
use Laravents\Verify\Dependency;
use Laravents\Verify\DependencyScanner;
use Laravents\Verify\Verifier;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * One module as Spring Modulith's module canvas sees it: the commands and queries it handles, the events in its
 * Api with their listeners and the models that publish them, the other modules' events it consumes, and the modules it depends on. Built from the
 * registry, the discovered classes, and the dependency scanner's references for the dependencies.
 *
 * @phpstan-type Handled array{routing_key: string|null, handler: string, message: string|null, interceptors: list<string>, http: string|null}
 * @phpstan-type Listener array{handler: string, module: string|null, mode: string, queue: string|null}
 * @phpstan-type Publication array{model: class-string, hook: string}
 * @phpstan-type Published array{event: class-string, listeners: list<Listener>, from: list<Publication>}
 * @phpstan-type Consumed array{event: class-string, handler: string, module: string, mode: string, queue: string|null}
 * @phpstan-type DependsOn array{module: string, classes: int, outside_api: int}
 * @phpstan-type Canvas array{module: string, namespace: string, commands: list<Handled>, queries: list<Handled>, events_published: list<Published>, events_consumed: list<Consumed>, depends_on: list<DependsOn>}
 */
final readonly class ModuleCanvas
{
    /**
     * @param  list<class-string>  $classes  The discovered classes, in discovery order
     * @param  list<Dependency>  $dependencies  The scanner's references from the discovered classes, interfaces, traits and enums
     * @param  Endpoints|null  $endpoints  Where the HTTP gateway serves each handler; null leaves http null
     */
    public function __construct(
        private Registry $registry,
        private array $classes,
        private string $rootNamespace,
        private array $dependencies,
        private ?Endpoints $endpoints = null,
    ) {}

    /** The canvas over laravents.paths and the modules namespace, as laravents:show and laravents:docs see it. */
    public static function fromConfig(Registry $registry, Repository $config): self
    {
        $paths = $config->get('laravents.paths', []);
        $paths = is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
        $namespace = $config->get('laravents.modules.namespace', 'Modules');
        $namespace = is_string($namespace) ? $namespace : 'Modules';

        return new self(
            $registry,
            ClassDiscovery::in($paths),
            $namespace,
            (new DependencyScanner($namespace))->scan(ClassDiscovery::structures($paths)),
            Endpoints::fromConfig($config),
        );
    }

    /**
     * Every module with a class in the discovered paths or a handler in the registry, sorted by name.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        $modules = [];

        foreach ($this->classes as $class) {
            $modules[] = RoutingKey::module($class, $this->rootNamespace);
        }

        foreach ($this->registry->all() as $handler) {
            $modules[] = $handler->module;
        }

        $modules = array_values(array_unique(array_filter($modules, is_string(...))));
        sort($modules);

        return $modules;
    }

    /** @return Canvas */
    public function for(string $module): array
    {
        return [
            'module' => $module,
            'namespace' => $this->namespace($module),
            'commands' => $this->handled($module, HandlerKind::Command),
            'queries' => $this->handled($module, HandlerKind::Query),
            'events_published' => $this->published($module),
            'events_consumed' => $this->consumed($module),
            'depends_on' => $this->dependencies($module),
        ];
    }

    /** @return list<Handled> */
    private function handled(string $module, HandlerKind $kind): array
    {
        return array_map(fn (HandlerDefinition $handler): array => [
            'routing_key' => $handler->routingKey,
            'handler' => $handler->handler(),
            'message' => $handler->messageClass,
            'interceptors' => array_map(
                static fn (AppliedInterceptor $applied): string => class_basename($applied->interceptor->pointcut),
                $this->registry->interceptorsFor($handler),
            ),
            'http' => $this->endpoints?->for($handler),
        ], $this->handlers($module, $kind));
    }

    /** @return list<Published> */
    private function published(string $module): array
    {
        $prefix = $this->namespace($module).'\\Api\\';
        $published = [];

        foreach ($this->classes as $class) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $listeners = $this->typedListeners($class);

            // A pattern can cover a command's derived key too (band.* and band.give_nickname), so only a typed listener makes an event.
            if (! $this->isEvent($class, $listeners !== [])) {
                continue;
            }

            $listeners = [...$listeners, ...$this->patternListeners($class)];

            $published[] = [
                'event' => $class,
                'listeners' => array_map(static fn (HandlerDefinition $handler): array => [
                    'handler' => $handler->handler(),
                    'module' => $handler->module,
                    'mode' => $handler->mode->value,
                    'queue' => $handler->queue,
                ], $listeners),
                'from' => array_values(array_map(
                    static fn (ModelPublication $publication): array => ['model' => $publication->model, 'hook' => $publication->hook],
                    array_filter($this->registry->modelPublications(), static fn (ModelPublication $publication): bool => $publication->event === $class),
                )),
            ];
        }

        return $published;
    }

    /** @return list<Consumed> */
    private function consumed(string $module): array
    {
        $consumed = [];

        foreach ($this->handlers($module, HandlerKind::Event) as $handler) {
            if ($handler->isPattern()) {
                array_push($consumed, ...$this->consumedByPattern($handler, $module));

                continue;
            }

            if ($handler->messageClass === null) {
                continue;
            }

            $from = RoutingKey::module($handler->messageClass, $this->rootNamespace) ?? $this->origin($handler, $handler->messageClass);

            if ($from === null || $from === $module) {
                continue;
            }

            $consumed[] = [
                'event' => $handler->messageClass,
                'handler' => $handler->handler(),
                'module' => $from,
                'mode' => $handler->mode->value,
                'queue' => $handler->queue,
            ];
        }

        return $consumed;
    }

    /**
     * The other modules' published events whose derived key the pattern handler's pattern covers, in discovery order.
     *
     * @return list<Consumed>
     */
    private function consumedByPattern(HandlerDefinition $handler, string $module): array
    {
        $consumed = [];

        foreach ($this->classes as $class) {
            $from = RoutingKey::module($class, $this->rootNamespace);

            if ($from === null || $from === $module || ! str_starts_with($class, $this->namespace($from).'\\Api\\') || ! in_array($handler, $this->patternListeners($class), true)) {
                continue;
            }

            if (! $this->isEvent($class, $this->typedListeners($class) !== [])) {
                continue;
            }

            $consumed[] = [
                'event' => $class,
                'handler' => $handler->handler(),
                'module' => $from,
                'mode' => $handler->mode->value,
                'queue' => $handler->queue,
            ];
        }

        return $consumed;
    }

    /**
     * The event handlers that take the class or one of its parents or interfaces as their first parameter.
     *
     * @param  class-string  $class
     * @return list<HandlerDefinition>
     */
    private function typedListeners(string $class): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            static fn (HandlerDefinition $handler): bool => $handler->kind === HandlerKind::Event
                && $handler->messageClass !== null
                && is_a($class, $handler->messageClass, true),
        ));
    }

    /**
     * The pattern handlers an event published as an object reaches: those whose pattern covers its derived key,
     * as the registry resolves them.
     *
     * @param  class-string  $class
     * @return list<HandlerDefinition>
     */
    private function patternListeners(string $class): array
    {
        $key = $this->registry->routingKeyFor($class, HandlerKind::Event);

        return $key === null ? [] : $this->registry->patternHandlersFor($key);
    }

    /**
     * Where a framework or application event an unkeyed handler takes comes from (Laravel's dispatcher hands it in):
     * Laravel for Illuminate's own, else the class's root namespace (App, a package's vendor). Null for a class
     * under the modules namespace that is in no module, and for a keyed handler, which Laravel does not reach.
     */
    private function origin(HandlerDefinition $handler, string $class): ?string
    {
        if ($handler->routingKey !== null || str_starts_with($class, trim($this->rootNamespace, '\\').'\\')) {
            return null;
        }

        $root = explode('\\', $class)[0];

        return $root === 'Illuminate' ? 'Laravel' : $root;
    }

    /**
     * The other modules this module's classes reference, through their Api or not, with the number of referencing
     * classes (interfaces, traits and enums included) and how many of them reach outside that module's Api, which
     * laravents:verify reports as direction violations: the same graph verify checks.
     *
     * @return list<DependsOn>
     */
    private function dependencies(string $module): array
    {
        $referencing = [];
        $outside = [];

        foreach ($this->dependencies as $dependency) {
            $target = RoutingKey::module($dependency->uses, $this->rootNamespace);

            if (RoutingKey::module($dependency->class, $this->rootNamespace) === $module && $target !== null && $target !== $module) {
                $referencing[$target][$dependency->class] = true;
            }
        }

        foreach ((new Verifier($this->rootNamespace))->direction($this->dependencies) as $violation) {
            if (RoutingKey::module($violation->class, $this->rootNamespace) === $module) {
                $outside[(string) RoutingKey::module($violation->uses, $this->rootNamespace)][$violation->class] = true;
            }
        }

        ksort($referencing);

        return array_map(
            static fn (string $target, array $classes): array => [
                'module' => $target,
                'classes' => count($classes),
                'outside_api' => count($outside[$target] ?? []),
            ],
            array_map(strval(...), array_keys($referencing)),
            array_values($referencing),
        );
    }

    /**
     * An event uses Publishable, has a listener, or is neither a command nor a query message nor a command's or
     * query's result.
     *
     * @param  class-string  $class
     */
    private function isEvent(string $class, bool $listenedTo): bool
    {
        $traits = class_uses_recursive($class);

        if (in_array(Publishable::class, $traits, true) || $listenedTo) {
            return true;
        }

        if (in_array(Sendable::class, $traits, true) || in_array(Askable::class, $traits, true)) {
            return false;
        }

        foreach ($this->registry->all() as $handler) {
            if ($handler->kind === HandlerKind::Event) {
                continue;
            }

            if ($handler->messageClass === $class || in_array($class, $this->returnTypes($handler), true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function returnTypes(HandlerDefinition $handler): array
    {
        $type = (new ReflectionMethod($handler->class, $handler->method))->getReturnType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_values(array_map(
            static fn (ReflectionNamedType $type): string => $type->getName(),
            array_filter($types, static fn (mixed $type): bool => $type instanceof ReflectionNamedType),
        ));
    }

    /** @return list<HandlerDefinition> */
    private function handlers(string $module, HandlerKind $kind): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            static fn (HandlerDefinition $handler): bool => $handler->kind === $kind && $handler->module === $module,
        ));
    }

    private function namespace(string $module): string
    {
        return trim($this->rootNamespace, '\\').'\\'.$module;
    }
}
