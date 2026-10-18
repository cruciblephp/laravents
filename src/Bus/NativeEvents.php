<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravents\Contracts\EventBus;
use Laravents\Discovery\Registry;
use WeakMap;

/**
 * Laravel's events into Laravents: one Event::listen() per framework or application class an #[EventHandler]
 * takes, which publishes the event on the event bus. From there it is the normal path: metadata (a fresh root
 * correlation unless Context has one, the actor from the guard), interceptors, sync, deferred or async.
 *
 * One listener per class, so an event with three handlers is published once and the engine fans out. Module
 * events are not subscribed: event(new NicknameGiven(...)) does nothing; Laravents::publish() is how they travel.
 * Under Event::fake() the event never reaches Laravents, as it reaches no other listener.
 *
 * Laravel calls a listener for the event's class and for each interface it implements, so an event whose class
 * and interface both have handlers would reach two of these listeners. Each event object is handed to the bus once
 * per process (a WeakMap, so nothing is kept alive), and the engine's is_a() matching reaches both handlers from
 * that one publish. Laravel does not call listeners of parent classes: a handler of an external parent class is
 * not reached for a subclass.
 */
final readonly class NativeEvents
{
    public function __construct(private Container $container) {}

    public function subscribe(Registry $registry, Dispatcher $events): void
    {
        $container = $this->container;
        /** @var WeakMap<object, true> $published */
        $published = new WeakMap;

        foreach ($registry->externalEventClasses() as $class) {
            // Resolved per event, so a Laravents::fake() bound later receives it.
            $events->listen($class, static function (object $event) use ($container, $published): void {
                if (isset($published[$event])) {
                    return;
                }

                $published[$event] = true;
                $container->make(EventBus::class)->publish($event);
            });
        }
    }
}
