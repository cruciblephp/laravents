<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Contracts\Container\Container;
use Laravents\Discovery\HandlerDefinition;

/**
 * Laravents' own command bus: a private Dispatcher with no queue resolver, so its pipes
 * never touch the application's jobs and faking the app's Bus does not affect it.
 */
final readonly class Engine
{
    public function __construct(private Container $container) {}

    public function dispatch(Message $message): mixed
    {
        // What Bus\Dispatcher::dispatchSync did for a Message: the MetadataPipe, then HandleMessage, each resolved per
        // dispatch as before, without the dispatcher's map, pipeline and handler lookup.
        return $this->container->make(MetadataPipe::class)->handle(
            $message,
            fn (Message $message): mixed => $this->container->make(HandleMessage::class)->handle($message),
        );
    }

    /**
     * Runs one handler for a message that already has its metadata, such as an event delivered by the queue.
     * Skips the metadata pipe: Context is seeded from the message's own headers, not from whatever Laravel
     * hydrated into the job, and put back afterwards. Messages the handler dispatches read their parent from
     * Context as usual, so the chain survives the queue. Engine-internal: not on the facade.
     */
    public function replay(Message $message, HandlerDefinition $handler): mixed
    {
        // Resolved per call: Context is a scoped binding that a queue worker replaces between jobs.
        return $this->container->make(ContextMetadata::class)->within(
            ContextMetadata::fromHeaders($message->headers),
            fn (): mixed => $this->container->make(HandleMessage::class)->invokeHandler($handler, $message),
        );
    }
}
