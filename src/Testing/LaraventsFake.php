<?php

declare(strict_types=1);

namespace Laravents\Testing;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\Fake;
use Illuminate\Support\Traits\ReflectsClosures;
use Laravents\Bus\MetadataPipe;
use Laravents\Bus\PayloadConverter;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\EventBus;
use Laravents\Contracts\QueryBus;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Exceptions\UnknownRoutingKeyException;
use LogicException;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * What Laravents::fake() binds to the three bus contracts: Event::fake() and Bus::fake() for commands, queries
 * and events. A faked message is recorded and no handler runs, so neither do the #[Asynchronous] handlers of a
 * faked event (no outbox row, no job; queue faking stays Queue::fake()). Anything not faked goes to the real
 * bus it wraps, headers and all. A message answers to its class and its routing key, both when faking and when
 * asserting, whichever way it was sent.
 */
final class LaraventsFake implements CommandBus, EventBus, Fake, QueryBus
{
    use ReflectsClosures;

    /** @var list<string|Closure> Classes, routing keys or closures: empty fakes everything */
    private readonly array $messagesToFake;

    /** @var list<string|Closure> Classes, routing keys or closures that run for real instead */
    private array $messagesToDispatch = [];

    /** @var array<value-of<HandlerKind>, list<RecordedMessage>> */
    private array $recorded = ['command' => [], 'query' => [], 'event' => []];

    /** @var array<string, mixed> By class or routing key: a value, or a closure taking the message and headers */
    private array $returns = [];

    /** @var array<string, mixed> By class or routing key: a value, or a closure taking the message and headers */
    private array $answers = [];

    /**
     * @param  CommandBus  $commandBus  The real buses, which anything not faked goes to
     * @param  array<array-key, string|Closure>|string|Closure  $messagesToFake
     */
    public function __construct(
        public readonly CommandBus $commandBus,
        public readonly QueryBus $queryBus,
        public readonly EventBus $eventBus,
        private readonly Registry $registry,
        private readonly PayloadConverter $converter,
        array|string|Closure $messagesToFake = [],
    ) {
        $this->messagesToFake = array_values(Arr::wrap($messagesToFake));
    }

    /**
     * Messages that run for real instead of being faked, as Event::fake()->except().
     *
     * @param  array<array-key, string|Closure>|string|Closure  $messagesToDispatch
     */
    public function except(array|string|Closure $messagesToDispatch): self
    {
        $this->messagesToDispatch = [...$this->messagesToDispatch, ...array_values(Arr::wrap($messagesToDispatch))];

        return $this;
    }

    /**
     * What a faked command returns: a value, or a closure that takes the command and its headers.
     *
     * @param  string  $command  A class or a routing key
     */
    public function returns(string $command, mixed $value): self
    {
        $this->returns[$command] = $value;

        return $this;
    }

    /**
     * What a faked query returns: a value, or a closure that takes the query and its headers.
     *
     * @param  string  $query  A class or a routing key
     */
    public function answers(string $query, mixed $value): self
    {
        $this->answers[$query] = $value;

        return $this;
    }

    // The bus contracts.

    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function send(string $routingKey, array|object $payload = [], array $headers = []): mixed
    {
        return $this->interceptKey(HandlerKind::Command, $routingKey, $payload, $headers, fn (): mixed => $this->commandBus->send($routingKey, $payload, $headers));
    }

    /**
     * Both CommandBus and QueryBus have dispatch(object), and this one class is bound to both, so the registry
     * tells which the object is: a command when it has a command handler, a query when it has a query handler.
     *
     * @param  array<string, scalar|null>  $headers
     */
    public function dispatch(object $message, array $headers = []): mixed
    {
        $commandKey = $this->registry->routingKeyFor($message::class, HandlerKind::Command);
        $queryKey = $this->registry->routingKeyFor($message::class, HandlerKind::Query);

        if ($commandKey !== null && $queryKey !== null) {
            throw new LogicException(sprintf('[%s] has both a command and a query handler, so the fake cannot tell which dispatch() means; send it by routing key: [%s] or [%s].', $message::class, $commandKey, $queryKey));
        }

        if ($commandKey !== null) {
            return $this->intercept(HandlerKind::Command, $message::class, $commandKey, $message, $headers, fn (): mixed => $this->commandBus->dispatch($message, $headers));
        }

        if ($queryKey !== null) {
            return $this->intercept(HandlerKind::Query, $message::class, $queryKey, $message, $headers, fn (): mixed => $this->queryBus->dispatch($message, $headers));
        }

        throw UnknownRoutingKeyException::forCommandOrQuery($message::class);
    }

    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function ask(string $routingKey, array|object $payload = [], array $headers = []): mixed
    {
        return $this->interceptKey(HandlerKind::Query, $routingKey, $payload, $headers, fn (): mixed => $this->queryBus->ask($routingKey, $payload, $headers));
    }

    /** @param array<string, scalar|null> $headers */
    public function publish(object $event, array $headers = []): void
    {
        $this->intercept(HandlerKind::Event, $event::class, null, $event, $headers, function () use ($event, $headers): null {
            $this->eventBus->publish($event, $headers);

            return null;
        });
    }

    /**
     * Zero handlers is a no-op for the real bus, so an event key needs no handler to be recorded. The payload is
     * hydrated when the key's handlers all take the same class.
     *
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     */
    public function publishTo(string $routingKey, array|object $payload, array $headers = []): void
    {
        $classes = array_values(array_unique(array_filter(array_map(
            static fn (HandlerDefinition $handler): ?string => $handler->messageClass,
            $this->registry->eventHandlersForKey($routingKey),
        ))));

        $class = match (true) {
            is_object($payload) => $payload::class,
            count($classes) === 1 => $classes[0],
            default => null,
        };

        $this->intercept(HandlerKind::Event, $class, $routingKey, $payload, $headers, function () use ($routingKey, $payload, $headers): null {
            $this->eventBus->publishTo($routingKey, $payload, $headers);

            return null;
        });
    }

    // The assertions, one set per kind under the names Laravel's fakes use (assertSent, assertNotSent, ...). Each is
    // one line into the shared helpers below, so the sets are parallel by design: the logic exists once.
    // phpcpd-ignore-start

    // Commands.

    /**
     * Assert if a command was sent based on a truth-test callback; an int asserts how many times.
     *
     * @param  string|Closure  $command  A class or a routing key, or a closure typed by the class
     * @param  callable|int|null  $callback  Takes the command and its headers
     */
    public function assertSent(string|Closure $command, callable|int|null $callback = null): void
    {
        $this->assertRecorded(HandlerKind::Command, $command, $callback);
    }

    /** Assert if a command was sent a number of times. */
    public function assertSentTimes(string|Closure $command, int $times = 1): void
    {
        $this->assertTimes(HandlerKind::Command, $command, $times);
    }

    /**
     * Determine if a command was sent based on a truth-test callback.
     *
     * @param  callable|null  $callback  Takes the command and its headers
     */
    public function assertNotSent(string|Closure $command, ?callable $callback = null): void
    {
        $this->assertNotRecorded(HandlerKind::Command, $command, $callback);
    }

    /** Assert that no commands were sent. */
    public function assertNothingSent(): void
    {
        $this->assertNothing(HandlerKind::Command);
    }

    /**
     * Get all of the commands matching a truth-test callback, as BusFake::dispatched().
     *
     * @param  callable|null  $callback  Takes the command and its headers
     * @return Collection<int, array<string, mixed>|object>
     */
    public function sent(string $command, ?callable $callback = null): Collection
    {
        return $this->recordedMatching(HandlerKind::Command, $command, $callback);
    }

    // Queries.

    /**
     * Assert if a query was asked based on a truth-test callback; an int asserts how many times.
     *
     * @param  callable|int|null  $callback  Takes the query and its headers
     */
    public function assertAsked(string|Closure $query, callable|int|null $callback = null): void
    {
        $this->assertRecorded(HandlerKind::Query, $query, $callback);
    }

    /** Assert if a query was asked a number of times. */
    public function assertAskedTimes(string|Closure $query, int $times = 1): void
    {
        $this->assertTimes(HandlerKind::Query, $query, $times);
    }

    /**
     * Determine if a query was asked based on a truth-test callback.
     *
     * @param  callable|null  $callback  Takes the query and its headers
     */
    public function assertNotAsked(string|Closure $query, ?callable $callback = null): void
    {
        $this->assertNotRecorded(HandlerKind::Query, $query, $callback);
    }

    /** Assert that no queries were asked. */
    public function assertNothingAsked(): void
    {
        $this->assertNothing(HandlerKind::Query);
    }

    /**
     * Get all of the queries matching a truth-test callback.
     *
     * @param  callable|null  $callback  Takes the query and its headers
     * @return Collection<int, array<string, mixed>|object>
     */
    public function asked(string $query, ?callable $callback = null): Collection
    {
        return $this->recordedMatching(HandlerKind::Query, $query, $callback);
    }

    // Events.

    /**
     * Assert if an event was published based on a truth-test callback; an int asserts how many times.
     *
     * @param  callable|int|null  $callback  Takes the event and its headers
     */
    public function assertPublished(string|Closure $event, callable|int|null $callback = null): void
    {
        $this->assertRecorded(HandlerKind::Event, $event, $callback);
    }

    /** Assert if an event was published a number of times. */
    public function assertPublishedTimes(string|Closure $event, int $times = 1): void
    {
        $this->assertTimes(HandlerKind::Event, $event, $times);
    }

    /**
     * Determine if an event was published based on a truth-test callback.
     *
     * @param  callable|null  $callback  Takes the event and its headers
     */
    public function assertNotPublished(string|Closure $event, ?callable $callback = null): void
    {
        $this->assertNotRecorded(HandlerKind::Event, $event, $callback);
    }

    /** Assert that no events were published. */
    public function assertNothingPublished(): void
    {
        $this->assertNothing(HandlerKind::Event);
    }

    /**
     * Get all of the events matching a truth-test callback.
     *
     * @param  callable|null  $callback  Takes the event and its headers
     * @return Collection<int, array<string, mixed>|object>
     */
    public function published(string $event, ?callable $callback = null): Collection
    {
        return $this->recordedMatching(HandlerKind::Event, $event, $callback);
    }

    // phpcpd-ignore-end

    // Internals.

    /**
     * intercept() for a command or query sent by routing key, which must have a handler.
     *
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     * @param  Closure(): mixed  $dispatch
     */
    private function interceptKey(HandlerKind $kind, string $routingKey, array|object $payload, array $headers, Closure $dispatch): mixed
    {
        $handler = $this->registry->handlerFor($kind, $routingKey)
            ?? throw UnknownRoutingKeyException::forKey($kind, $routingKey, $this->registry->routingKeys($kind));

        return $this->intercept($kind, $handler->messageClass ?? (is_object($payload) ? $payload::class : null), $routingKey, $payload, $headers, $dispatch);
    }

    /**
     * Records the message and returns its fake answer, or hands it to the real bus.
     *
     * @param  class-string|null  $class
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, scalar|null>  $headers
     * @param  Closure(): mixed  $dispatch  The real bus, with the message as it was sent
     */
    private function intercept(HandlerKind $kind, ?string $class, ?string $routingKey, array|object $payload, array $headers, Closure $dispatch): mixed
    {
        $hydrated = null;
        $message = function () use (&$hydrated, $class, $payload): array|object {
            return $hydrated ??= $class === null ? $payload : $this->converter->convert($class, $payload);
        };

        if (! $this->shouldFake($class, $routingKey, $message, $headers)) {
            return $dispatch();
        }

        MetadataPipe::refuseReserved($headers);

        $recorded = new RecordedMessage($kind, $message(), $headers, $class, $routingKey);
        $this->recorded[$kind->value][] = $recorded;

        return $this->answer($recorded);
    }

    /**
     * EventFake::shouldFakeEvent(): anything in except() runs; otherwise everything is faked, or only what
     * fake() named.
     *
     * @param  Closure(): (array<string, mixed>|object)  $message
     * @param  array<string, scalar|null>  $headers
     */
    private function shouldFake(?string $class, ?string $routingKey, Closure $message, array $headers): bool
    {
        if ($this->matchesAny($this->messagesToDispatch, $class, $routingKey, $message, $headers)) {
            return false;
        }

        return $this->messagesToFake === [] || $this->matchesAny($this->messagesToFake, $class, $routingKey, $message, $headers);
    }

    /**
     * @param  list<string|Closure>  $patterns
     * @param  Closure(): (array<string, mixed>|object)  $message
     * @param  array<string, scalar|null>  $headers
     */
    private function matchesAny(array $patterns, ?string $class, ?string $routingKey, Closure $message, array $headers): bool
    {
        foreach ($patterns as $pattern) {
            $matches = $pattern instanceof Closure
                ? (bool) $pattern($message(), $headers)
                : $pattern === $class || $pattern === $routingKey;

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** A faked command's or query's return: what returns() or answers() set for its class, else its key, else null. */
    private function answer(RecordedMessage $recorded): mixed
    {
        $answers = match ($recorded->kind) {
            HandlerKind::Command => $this->returns,
            HandlerKind::Query => $this->answers,
            HandlerKind::Event => [],
        };

        foreach ([$recorded->class, $recorded->routingKey] as $name) {
            if ($name !== null && array_key_exists($name, $answers)) {
                $answer = $answers[$name];

                return $answer instanceof Closure ? $answer($recorded->message, $recorded->headers) : $answer;
            }
        }

        return null;
    }

    /**
     * @param  callable|null  $callback  Takes the message and its headers
     * @return Collection<int, array<string, mixed>|object>
     */
    private function recordedMatching(HandlerKind $kind, string $name, ?callable $callback): Collection
    {
        return (new Collection($this->recorded[$kind->value]))
            ->filter(static fn (RecordedMessage $recorded): bool => $recorded->is($name)
                && ($callback === null || (bool) $callback($recorded->message, $recorded->headers)))
            ->map(static fn (RecordedMessage $recorded): array|object => $recorded->message)
            ->values();
    }

    /**
     * assertSent(), assertAsked() and assertPublished(), per kind: a closure stands for its parameter's class and
     * is the callback; an int asserts how many times.
     *
     * @param  callable|int|null  $callback  Takes the message and its headers
     */
    private function assertRecorded(HandlerKind $kind, string|Closure $name, callable|int|null $callback): void
    {
        if ($name instanceof Closure) {
            [$name, $callback] = [$this->firstClosureParameterType($name), $name];
        }

        if (is_int($callback)) {
            $this->assertTimes($kind, $name, $callback);

            return;
        }

        PHPUnit::assertTrue(
            $this->recordedMatching($kind, $name, $callback)->isNotEmpty(),
            sprintf('The expected [%s] %s was not %s.', $name, $kind->value, $this->verb($kind)),
        );
    }

    /**
     * assertNotSent(), assertNotAsked() and assertNotPublished(), per kind.
     *
     * @param  callable|null  $callback  Takes the message and its headers
     */
    private function assertNotRecorded(HandlerKind $kind, string|Closure $name, ?callable $callback): void
    {
        if ($name instanceof Closure) {
            [$name, $callback] = [$this->firstClosureParameterType($name), $name];
        }

        PHPUnit::assertCount(
            0, $this->recordedMatching($kind, $name, $callback),
            sprintf('The unexpected [%s] %s was %s.', $name, $kind->value, $this->verb($kind)),
        );
    }

    private function assertTimes(HandlerKind $kind, string|Closure $name, int $times): void
    {
        $callback = null;

        if ($name instanceof Closure) {
            [$name, $callback] = [$this->firstClosureParameterType($name), $name];
        }

        $count = $this->recordedMatching($kind, $name, $callback)->count();

        PHPUnit::assertSame(
            $times, $count,
            sprintf(
                "The expected [{$name}] %s was %s {$count} %s instead of {$times} %s.",
                $kind->value,
                $this->verb($kind),
                Str::plural('time', $count),
                Str::plural('time', $times),
            )
        );
    }

    /** EventFake::assertNothingDispatched(), per kind. */
    private function assertNothing(HandlerKind $kind): void
    {
        $count = count($this->recorded[$kind->value]);

        $names = (new Collection($this->recorded[$kind->value]))
            ->countBy(static fn (RecordedMessage $recorded): string => $recorded->name())
            ->map(fn (int $times, string $name): string => sprintf(
                '%s %s %s %s',
                $name,
                $this->verb($kind),
                $times,
                Str::plural('time', $times),
            ))
            ->join("\n- ");

        PHPUnit::assertSame(
            0, $count,
            sprintf("{$count} unexpected %s were %s:\n\n- {$names}\n", $kind === HandlerKind::Query ? 'queries' : $kind->value.'s', $this->verb($kind)),
        );
    }

    private function verb(HandlerKind $kind): string
    {
        return match ($kind) {
            HandlerKind::Command => 'sent',
            HandlerKind::Query => 'asked',
            HandlerKind::Event => 'published',
        };
    }
}
