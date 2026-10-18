<?php

declare(strict_types=1);

use Laravents\Bus\HeaderValue;

test('a header or Context value is a string when it is a string or an int, and null otherwise', function (mixed $value, ?string $expected): void {
    expect(HeaderValue::string($value))->toBe($expected);
})->with([
    'string' => ['01JABC', '01JABC'],
    'empty string' => ['', ''],
    'int' => [42, '42'],
    'negative int' => [-7, '-7'],
    'null' => [null, null],
    'float' => [4.2, null],
    'true' => [true, null],
    'false' => [false, null],
    'array' => [['x'], null],
]);
