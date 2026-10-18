<?php

declare(strict_types=1);

namespace Laravents\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A handler's result as the gateway answers it: a Responsable (a JsonResource is one) as is, null as 204,
 * anything else as {"data": ...} with 200, through Laravel's JSON serialization.
 */
final class HandlerResponse
{
    public static function from(mixed $result): Responsable|Response|JsonResponse
    {
        return match (true) {
            $result instanceof Responsable => $result,
            $result === null => new Response(status: Response::HTTP_NO_CONTENT),
            default => new JsonResponse(['data' => $result]),
        };
    }
}
