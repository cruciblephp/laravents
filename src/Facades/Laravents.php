<?php

declare(strict_types=1);

namespace Laravents\Facades;

use BadMethodCallException;
use Closure;
use Illuminate\Support\Facades\Facade;
use Laravents\Auth\ActAs;
use Laravents\Bus\Gateway;
use Laravents\Bus\PayloadConverter;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\EventBus;
use Laravents\Contracts\QueryBus;
use Laravents\Discovery\Module;
use Laravents\Discovery\Registry;
use Laravents\Testing\LaraventsFake;
use LogicException;
use RuntimeException;

/**
 * Commands, queries and events by object or by routing key. Headers go through the pending builder:
 * Laravents::withHeaders(['correlation_id' => 'req-1'])->command('band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One']); an actor
 * the same way: Laravents::as($user)->command(...).
 *
 * @method static \Laravents\Bus\PendingMessage as(\Illuminate\Contracts\Auth\Authenticatable $user, string|null $guard = null) Start a message dispatched as this user: for that one dispatch the user is on the guard (the default when null) and is the actor of the message and everything dispatched inside it.
 * @method static \Laravents\Bus\PendingMessage withHeader(string $name, int|float|string|bool|null $value) Start a message with one header.
 * @method static \Laravents\Bus\PendingMessage withHeaders(array<string, scalar|null> $headers) Start a message with these headers.
 * @method static mixed command(string|object $command, array<string, mixed>|object $payload = [], array<string, scalar|null> $headers = []) Send a command to its one handler and return the handler's result. An object finds its key in the registry; a key takes the payload.
 * @method static mixed query(string|object $query, array<string, mixed>|object $payload = [], array<string, scalar|null> $headers = []) Ask a query of its one handler and return the handler's result. An object finds its key in the registry; a key takes the payload.
 * @method static void publish(string|object $event, array<string, mixed>|object $payload = [], array<string, scalar|null> $headers = []) Publish an event. An object reaches every handler that matches its type, then the #[EventHandler('band.*')] patterns covering its derived key; a key reaches the handlers registered with #[EventHandler('key')], then the patterns covering it, and takes the payload. Zero handlers is a no-op.
 * @method static void assertSent(string|\Closure $command, callable|int|null $callback = null) After fake(): the command was sent (a class or a routing key; the callback takes the command and its headers, an int is the number of times).
 * @method static void assertSentTimes(string|\Closure $command, int $times = 1) After fake(): the command was sent this many times.
 * @method static void assertNotSent(string|\Closure $command, callable|null $callback = null) After fake(): the command was not sent.
 * @method static void assertNothingSent() After fake(): no command was sent.
 * @method static \Illuminate\Support\Collection<int, array<string, mixed>|object> sent(string $command, callable|null $callback = null) After fake(): the commands sent.
 * @method static void assertAsked(string|\Closure $query, callable|int|null $callback = null) After fake(): the query was asked.
 * @method static void assertAskedTimes(string|\Closure $query, int $times = 1) After fake(): the query was asked this many times.
 * @method static void assertNotAsked(string|\Closure $query, callable|null $callback = null) After fake(): the query was not asked.
 * @method static void assertNothingAsked() After fake(): no query was asked.
 * @method static \Illuminate\Support\Collection<int, array<string, mixed>|object> asked(string $query, callable|null $callback = null) After fake(): the queries asked.
 * @method static void assertPublished(string|\Closure $event, callable|int|null $callback = null) After fake(): the event was published.
 * @method static void assertPublishedTimes(string|\Closure $event, int $times = 1) After fake(): the event was published this many times.
 * @method static void assertNotPublished(string|\Closure $event, callable|null $callback = null) After fake(): the event was not published.
 * @method static void assertNothingPublished() After fake(): no event was published.
 * @method static \Illuminate\Support\Collection<int, array<string, mixed>|object> published(string $event, callable|null $callback = null) After fake(): the events published.
 *
 * @see Gateway
 * @see LaraventsFake
 */
final class Laravents extends Facade
{
    /**
     * Replace the buses with a fake that records messages instead of handling them, as Event::fake() and
     * Bus::fake(). With no argument everything is faked; with classes or routing keys, only those, and the
     * rest runs for real. The fake is bound to the three bus contracts and the facade root becomes a Gateway
     * built on it, so GiveNickname::send(), Laravents::command(), app(CommandBus::class) and a handler's injected
     * bus all reach it. It lives in the container, so the next test's fresh application starts real again.
     *
     * @param  array<array-key, string|Closure>|string|Closure  $messagesToFake
     */
    public static function fake(array|string|Closure $messagesToFake = []): LaraventsFake
    {
        $app = self::$app ?? throw new RuntimeException('A facade root has not been set.');

        // Faking twice wraps the real buses again, not the first fake, as Event::fake() does.
        $previous = self::isFake() ? $app->make(LaraventsFake::class) : null;

        $fake = new LaraventsFake(
            $previous->commandBus ?? $app->make(CommandBus::class),
            $previous->queryBus ?? $app->make(QueryBus::class),
            $previous->eventBus ?? $app->make(EventBus::class),
            $app->make(Registry::class),
            $app->make(PayloadConverter::class),
            $messagesToFake,
        );

        $app->instance(LaraventsFake::class, $fake);
        $app->instance(CommandBus::class, $fake);
        $app->instance(QueryBus::class, $fake);
        $app->instance(EventBus::class, $fake);

        // swap() replaces any Gateway the facade already resolved, and the container's Gateway too.
        self::swap(new Gateway($fake, $fake, $fake, $app->make(ActAs::class)));

        return $fake;
    }

    /**
     * Every module by name, sorted, the disabled ones included, as discovery found them: the registry's list, so a
     * cached registry answers it without a scan. Not a message, so fake() leaves it as it is.
     *
     * @return array<string, Module>
     */
    public static function modules(): array
    {
        return self::registry()->modules();
    }

    /** One module by name, or null when there is none. */
    public static function module(string $name): ?Module
    {
        return self::registry()->module($name);
    }

    /**
     * A module's manifest, typed as the application's manifest class: Laravents::manifest('Band', Manifest::class)?->version.
     * Null when there is no such module or it has no module.json; asking for another class than
     * laravents.modules.manifest is a mistake in the caller, and throws.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public static function manifest(string $module, string $class): ?object
    {
        $manifest = self::registry()->module($module)?->manifest;

        if ($manifest === null || $manifest instanceof $class) {
            return $manifest;
        }

        throw new LogicException(sprintf('The manifests are %s, not %s: laravents.modules.manifest names the class.', $manifest::class, $class));
    }

    /** Whether fake() has replaced the buses. The facade root is a Gateway either way, so it asks the container. */
    public static function isFake(): bool
    {
        return self::$app?->bound(LaraventsFake::class) === true;
    }

    /**
     * The assertions live on the fake, not on the Gateway, so Laravents::assertSent() reaches the fake as
     * Event::assertDispatched() reaches EventFake. The bus methods stay the Gateway's, faked or not.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     */
    public static function __callStatic($method, $args): mixed
    {
        $onFakeOnly = method_exists(LaraventsFake::class, $method) && ! method_exists(Gateway::class, $method)
            && ! method_exists(CommandBus::class, $method) && ! method_exists(QueryBus::class, $method) && ! method_exists(EventBus::class, $method);

        if (! $onFakeOnly) {
            return parent::__callStatic($method, $args);
        }

        if (! self::isFake() || self::$app === null) {
            throw new BadMethodCallException(sprintf('Laravents::%s() needs Laravents::fake() first.', $method));
        }

        return self::$app->make(LaraventsFake::class)->{$method}(...$args);
    }

    protected static function getFacadeAccessor(): string
    {
        return Gateway::class;
    }

    private static function registry(): Registry
    {
        $app = self::$app ?? throw new RuntimeException('A facade root has not been set.');

        return $app->make(Registry::class);
    }
}
