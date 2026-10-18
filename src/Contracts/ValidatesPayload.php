<?php

declare(strict_types=1);

namespace Laravents\Contracts;

/**
 * A message whose fields are validated, with Laravel's own rule syntax, before it is hydrated from a request:
 * by the HTTP gateway and by fromRequest(). A failure is Laravel's ValidationException, a 422.
 */
interface ValidatesPayload
{
    /** @return array<string, mixed> */
    public static function rules(): array;
}
