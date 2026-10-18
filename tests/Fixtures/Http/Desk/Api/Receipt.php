<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Http\Desk\Api;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/**
 * A result that answers for itself: the gateway must hand it over as is, status and headers included.
 */
final readonly class Receipt implements Responsable
{
    public function __construct(public int $id) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['receipt' => $this->id], 202, ['X-Receipt' => (string) $this->id]);
    }
}
