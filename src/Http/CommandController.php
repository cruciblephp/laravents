<?php

declare(strict_types=1);

namespace Laravents\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST {prefix}/commands/{key}: sends an #[Expose]d command with the request body as its payload, and
 * X-Correlation-ID as its correlation_id.
 */
final readonly class CommandController
{
    public function __invoke(Request $request, string $key, ExposedDispatcher $dispatcher, RequestPayload $payload): Responsable|Response|JsonResponse
    {
        return HandlerResponse::from($dispatcher->command($key, $request->request->all(), $payload->headers($request)));
    }
}
