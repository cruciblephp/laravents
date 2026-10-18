<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Validation\ValidationException;

interface PayloadConverter
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|object  $payload
     * @return T
     *
     * @throws ValidationException when the payload cannot hydrate the class
     */
    public function convert(string $class, array|object $payload): object;
}
