<?php

declare(strict_types=1);

namespace Laravents\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET|POST {prefix}/queries/{key}: asks an #[Expose]d query with the query string (GET) or the request body (POST),
 * and X-Correlation-ID as its correlation_id.
 */
final readonly class QueryController
{
    public function __invoke(Request $request, string $key, ExposedDispatcher $dispatcher, RequestPayload $payload): Responsable|Response|JsonResponse
    {
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();

        return HandlerResponse::from($dispatcher->query($key, $input, $payload->headers($request)));
    }
}
