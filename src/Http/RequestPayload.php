<?php

declare(strict_types=1);

namespace Laravents\Http;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravents\Bus\PayloadConverter;
use Laravents\Contracts\ValidatesPayload;

/**
 * Request input on its way to a message: validated with the message's rules() when it implements ValidatesPayload,
 * then hydrated by the PayloadConverter. Both failures are a ValidationException, so a 422.
 */
final readonly class RequestPayload
{
    /** The request header a client sets to join an existing trace; it becomes the message's correlation_id. */
    public const string CORRELATION_HEADER = 'X-Correlation-ID';

    public function __construct(
        private Factory $validator,
        private PayloadConverter $converter,
    ) {}

    /**
     * The input's named fields; a list body (a JSON array) has none.
     *
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    public static function fields(array $input): array
    {
        return array_filter($input, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The message headers the request carries: correlation_id from X-Correlation-ID, validated to fit the outbox's
     * correlation_id column.
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function headers(Request $request): array
    {
        if (! $request->headers->has(self::CORRELATION_HEADER)) {
            return [];
        }

        $correlationId = $request->headers->get(self::CORRELATION_HEADER);
        $this->validator->make(['correlation_id' => $correlationId], ['correlation_id' => 'required|string|max:255'])->validate();

        return is_string($correlationId) ? ['correlation_id' => $correlationId] : [];
    }

    /**
     * The validated fields, as $request->validate() returns them, when the message has rules(); else the fields as given.
     *
     * @param  class-string|null  $class
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(?string $class, array $fields): array
    {
        if ($class === null || ! is_a($class, ValidatesPayload::class, true)) {
            return $fields;
        }

        return self::fields($this->validator->make($fields, $class::rules())->validate());
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<mixed>  $input
     * @return T
     *
     * @throws ValidationException
     */
    public function message(string $class, array $input): object
    {
        return $this->converter->convert($class, $this->validated($class, self::fields($input)));
    }
}
