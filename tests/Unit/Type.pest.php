<?php

declare(strict_types=1);

use Laravents\Invocation\Type;
use Laravents\Tests\Fixtures\Types\Takes;

/**
 * The values every type is tried with: each scalar edge PHP's coercion draws a line at, and the non-scalars.
 *
 * @return array<string, mixed>
 */
function typeProbes(): array
{
    return [
        'int' => 1, 'zero' => 0, 'negative' => -3,
        'whole float' => 1.0, 'fractional float' => 1.5, 'infinity' => INF, 'not a number' => NAN,
        'true' => true, 'false' => false,
        'numeric string' => '5', 'padded numeric string' => ' 5 ', 'decimal string' => '5.5', 'exponent string' => '1e3',
        'word' => 'abc', 'empty string' => '', 'hex string' => '0x1A',
        'null' => null,
        'empty array' => [], 'list' => [1],
        'object' => new stdClass, 'stringable' => new class implements Stringable
        {
            public function __toString(): string
            {
                return 's';
            }
        },
        'closure' => static fn (): null => null, 'countable' => new ArrayIterator([1]), 'takes' => new Takes,
    ];
}

/** Whether PHP takes the value: through reflection, coercive as the container calls; or directly, strict as this file is. */
function phpTakes(string $method, mixed $value, bool $strict): bool
{
    set_error_handler(static fn (): bool => true, E_DEPRECATED);

    try {
        $strict ? (new Takes)->{$method}($value) : (new ReflectionMethod(Takes::class, $method))->invoke(new Takes, $value);

        return true;
    } catch (TypeError) {
        return false;
    } finally {
        restore_error_handler();
    }
}

test('accepts() answers as PHP does, value by value, coercive and strict', function (string $method): void {
    $type = Type::of(new ReflectionParameter([Takes::class, $method], 'value'));

    $typeAnswers = [];
    $phpAnswers = [];

    // Whole maps, label by label, so a failure names every value Type and PHP disagree on.
    foreach (typeProbes() as $label => $value) {
        $typeAnswers["coercive: {$label}"] = $type->accepts($value, strict: false);
        $phpAnswers["coercive: {$label}"] = phpTakes($method, $value, false);
        $typeAnswers["strict: {$label}"] = $type->accepts($value);
        $phpAnswers["strict: {$label}"] = phpTakes($method, $value, true);
    }

    expect($typeAnswers)->toBe($phpAnswers);
})->with(['int', 'float', 'string', 'bool', 'nullableInt', 'intOrString', 'array', 'iterable', 'object', 'callable', 'mixed', 'false', 'true', 'class', 'interface', 'untyped']);

test('names() is every named member, named() the one class name, class() that name when it exists', function (): void {
    $of = static fn (string $method): Type => Type::of(new ReflectionParameter([Takes::class, $method], 'value'));

    expect($of('nullableInt')->names())->toBe(['int'])
        ->and($of('intOrString')->names())->toBe(['string', 'int'])
        ->and($of('untyped')->names())->toBe([])
        ->and($of('class')->named())->toBe(Takes::class)
        ->and($of('interface')->class())->toBe(Countable::class)
        ->and($of('int')->named())->toBeNull()
        ->and($of('intOrString')->named())->toBeNull()
        ->and($of('untyped')->class())->toBeNull()
        ->and((string) $of('nullableInt'))->toBe('?int')
        ->and(Type::of(null)->accepts('anything'))->toBeTrue();
});

test('class() is null for a class name nothing declares, which named() still gives', function (): void {
    // A type naming a class no file declares cannot sit in a file the analyser reads, so the closure is built here.
    $closure = eval('return static function (\Missing\Nowhere $value): void {};');

    expect($closure)->toBeInstanceOf(Closure::class);
    $type = Type::of((new ReflectionFunction($closure))->getParameters()[0]);

    expect($type->named())->toBe('Missing\Nowhere')
        ->and($type->class())->toBeNull();
});
