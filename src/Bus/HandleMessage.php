<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Closure;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Pipeline\Pipeline;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Discovery\AppliedInterceptor;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\InterceptorKind;
use Laravents\Discovery\Registry;
use Laravents\Exceptions\UnknownRoutingKeyException;
use Laravents\Invocation\Arguments;
use Laravents\Invocation\MethodInvocation;
use Laravents\Outbox\EventPublication;
use LogicException;
use ReflectionMethod;

use function Illuminate\Support\defer;

/**
 * The engine's one handler: finds the handler definitions for a message and invokes each one
 * through its interceptors, defers it when it is a #[Deferred] event handler, or queues it when it
 * is an #[Asynchronous] one.
 */
final readonly class HandleMessage
{
    public function __construct(
        private Container $container,
        private Registry $registry,
        private PayloadConverter $converter,
        private Dispatcher $bus,
        private ?DatabaseTransactionsManager $transactions = null,
    ) {}

    public function handle(Message $message): mixed
    {
        if ($message->kind === HandlerKind::Event) {
            foreach ($this->eventHandlers($message) as $handler) {
                if ($handler->isAsynchronous()) {
                    $this->queue($handler, $message);
                } elseif ($handler->isDeferred()) {
                    $this->defer($handler, $message);
                } else {
                    $this->invokeHandler($handler, $message);
                }
            }

            return null;
        }

        return $this->invokeHandler(
            $this->registry->handlerFor($message->kind, $message->routingKey) ?? throw UnknownRoutingKeyException::forKey($message->kind, $message->routingKey, $this->registry->routingKeys($message->kind)),
            $message,
        );
    }

    /**
     * Runs one handler through the interceptors its pointcuts match, outermost first. The single path
     * for inline handlers and for asynchronous ones replayed from the queue. With no interceptor (so no
     * #[Authorize] and no #[Transactional] either) the handler is called as the Pipeline would call it.
     */
    public function invokeHandler(HandlerDefinition $handler, Message $message): mixed
    {
        $interceptors = $this->registry->interceptorsFor($handler);

        if ($interceptors === []) {
            return $this->callHandler($handler, $message);
        }

        $pipes = array_map(
            fn (AppliedInterceptor $applied): Closure => $this->pipe($applied, $handler),
            $interceptors,
        );

        return (new Pipeline($this->container))
            ->send($message)
            ->through($pipes)
            ->then(fn (Message $message): mixed => $this->callHandler($handler, $message));
    }

    /** @return list<HandlerDefinition> */
    private function eventHandlers(Message $message): array
    {
        $byType = is_object($message->payload) && $message->routingKey === $message->payload::class;

        return $byType
            ? $this->registry->eventHandlersForObject($message->payload)
            : $this->registry->eventHandlersForKey($message->routingKey);
    }

    /**
     * Writes the outbox row inside the publisher's transaction (on the connection of the innermost open
     * transaction, else the default), then hands it to a job, which serializes only its id and connection and
     * reads the row it was handed when the queue asks its display name at the push. The job goes
     * through the application's Bus, not the engine's: it is an ordinary Laravel job, so Bus::fake() and
     * Queue::fake() see it, and ShouldQueueAfterCommit attaches the push to the innermost open transaction,
     * so it runs when that transaction's connection commits its outermost level, or is dropped with the row on rollback.
     */
    private function queue(HandlerDefinition $handler, Message $message): void
    {
        $publication = EventPublication::record($handler->handler(), $message);

        $this->bus->dispatch((new ExecuteHandlerJob($publication->id, $publication->getConnectionName()))->withPublication($publication)->onQueue($handler->queue));
    }

    /**
     * Hands the handler to Laravel's defer() with the message as published, headers and all, so it runs after the
     * response as a replay: Context from the message's own ids, interceptors as for any handler, and whoever is on
     * the guard then. Inside a transaction it is registered by Laravel's after-commit callback on the innermost open
     * transaction, when that transaction's connection commits its outermost level (as for ShouldQueueAfterCommit), so a
     * rollback drops it whether or not the exception is caught.
     * No outbox row, no retry: Laravel's rescue() reports what the handler throws.
     */
    private function defer(HandlerDefinition $handler, Message $message): void
    {
        $container = $this->container;
        $register = static function () use ($container, $handler, $message): void {
            defer(static fn (): mixed => $container->make(Engine::class)->replay($message, $handler));
        };

        if ($this->transactions instanceof DatabaseTransactionsManager) {
            $this->transactions->addCallback($register);
        } else {
            $register();
        }
    }

    private function pipe(AppliedInterceptor $applied, HandlerDefinition $handler): Closure
    {
        return match ($applied->interceptor->kind) {
            InterceptorKind::Before => function (Message $message, Closure $next) use ($applied, $handler): mixed {
                $this->callInterceptor($applied, $handler, $message);

                return $next($message);
            },
            InterceptorKind::After => function (Message $message, Closure $next) use ($applied, $handler): mixed {
                $result = $next($message);
                $this->callInterceptor($applied, $handler, $message, byName: ['result' => $result]);

                return $result;
            },
            InterceptorKind::Around => fn (Message $message, Closure $next): mixed => $this->callInterceptor(
                $applied,
                $handler,
                $message,
                [new MethodInvocation(static fn (): mixed => $next($message), $message, $handler)],
            ),
        };
    }

    /**
     * @param  list<object>  $byType
     * @param  array<string, mixed>  $byName
     */
    private function callInterceptor(AppliedInterceptor $applied, HandlerDefinition $handler, Message $message, array $byType = [], array $byName = []): mixed
    {
        $interceptor = $applied->interceptor;
        $byType = [...$byType, $message];

        if ($applied->attribute !== null) {
            $byType[] = $applied->attribute;
        }

        if (is_object($message->payload)) {
            $byType[] = $message->payload;
        }

        return $this->call($interceptor->class, $interceptor->method, Arguments::for($interceptor->class, $interceptor->method, $message, $handler, $byType, $byName), $interceptor->location());
    }

    private function callHandler(HandlerDefinition $handler, Message $message): mixed
    {
        $first = Arguments::plan($handler->class, $handler->method)[0] ?? null;
        $arguments = Arguments::for($handler->class, $handler->method, $message, $handler, skip: 1);

        if ($first !== null) {
            $arguments[$first->name] = $handler->messageClass !== null
                ? $this->converter->convert($handler->messageClass, $message->payload)
                : $message->payload;
        }

        return $this->call($handler->class, $handler->method, $arguments, $handler->location());
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $arguments
     */
    private function call(string $class, string $method, array $arguments, string $location): mixed
    {
        $instance = $this->container->make($class);
        $callable = [$instance, $method];

        if (! is_object($instance) || ! is_callable($callable)) {
            throw new LogicException(sprintf('%s is not callable.', $location));
        }

        $direct = $this->direct($class, $method, $instance, $arguments);

        // Through reflection, as the container's call does from a file without strict_types: arguments are coerced alike.
        return $direct instanceof ReflectionMethod
            ? $direct->invokeArgs($instance, $arguments)
            : $this->container->call($callable, $arguments);
    }

    /**
     * The method, when calling it with $arguments is what the container's call would do: they name every parameter
     * and nothing else, the container built $class itself, and holds no method binding for it and no contextual
     * binding the method's body would be resolved in. Else null.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $arguments
     */
    private function direct(string $class, string $method, object $instance, array $arguments): ?ReflectionMethod
    {
        $reflection = Arguments::direct($class, $method);

        if (! $reflection instanceof ReflectionMethod || $instance::class !== $class || count($arguments) !== $reflection->getNumberOfParameters()
            || ! $this->container instanceof IlluminateContainer || $this->container->hasMethodBinding($class.'@'.$method)
            || isset($this->container->contextual[$class]) || isset($this->container->contextual[$reflection->class])) {
            return null;
        }

        foreach (Arguments::plan($class, $method) as $parameter) {
            if (! array_key_exists($parameter->name, $arguments)) {
                return null;
            }
        }

        return $reflection;
    }
}
