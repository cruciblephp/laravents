<?php

declare(strict_types=1);

namespace Laravents\Http;

use Illuminate\Validation\ValidationException;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\QueryBus;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\Registry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The HTTP boundary's rules in one place, for the gateway's controllers and for any adapter that puts its own wire
 * in front of the buses: only an #[Expose]d handler of the asked kind is reachable, its fields are validated with
 * the message's rules(), and the result comes back as the handler returned it. The adapter owns everything else:
 * its envelope, batching, idempotency and error bodies.
 */
final readonly class ExposedDispatcher
{
    public function __construct(
        private Registry $registry,
        private RequestPayload $payload,
        private CommandBus $commands,
        private QueryBus $queries,
    ) {}

    /**
     * Sends the #[Expose]d command with these fields and headers.
     *
     * @param  array<mixed>  $fields
     * @param  array<string, scalar|null>  $headers  correlation_id and the adapter's own; a reserved one is refused by the bus
     *
     * @throws NotFoundHttpException when no exposed command has this key
     * @throws ValidationException when the fields fail rules() or cannot build the command
     */
    public function command(string $key, array $fields, array $headers = []): mixed
    {
        $handler = $this->exposed($this->registry->commandHandler($key));

        return $this->commands->send($key, $this->payload->validated($handler->messageClass, RequestPayload::fields($fields)), $headers);
    }

    /**
     * Asks the #[Expose]d query with these fields and headers.
     *
     * @param  array<mixed>  $fields
     * @param  array<string, scalar|null>  $headers  correlation_id and the adapter's own; a reserved one is refused by the bus
     *
     * @throws NotFoundHttpException when no exposed query has this key
     * @throws ValidationException when the fields fail rules() or cannot build the query
     */
    public function query(string $key, array $fields, array $headers = []): mixed
    {
        $handler = $this->exposed($this->registry->queryHandler($key));

        return $this->queries->ask($key, $this->payload->validated($handler->messageClass, RequestPayload::fields($fields)), $headers);
    }

    /**
     * Unknown, not exposed, or a key of the other kind: one 404, the exception abort(404) throws, decided before the
     * bus can suggest a key.
     */
    private function exposed(?HandlerDefinition $handler): HandlerDefinition
    {
        if (! $handler instanceof HandlerDefinition || ! $handler->exposed) {
            throw new NotFoundHttpException;
        }

        return $handler;
    }
}
