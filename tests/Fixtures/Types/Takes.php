<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Types;

use Countable;

/**
 * One method per kind of declared type: PHP itself says which values each takes, called through reflection (coercive,
 * as the container calls a constructor) and directly from a strict_types file.
 */
final class Takes
{
    public function int(int $value): void {}

    public function float(float $value): void {}

    public function string(string $value): void {}

    public function bool(bool $value): void {}

    public function nullableInt(?int $value): void {}

    public function intOrString(int|string $value): void {}

    /** @param array<mixed> $value */
    public function array(array $value): void {}

    /** @param iterable<mixed> $value */
    public function iterable(iterable $value): void {}

    public function object(object $value): void {}

    public function callable(callable $value): void {}

    public function mixed(mixed $value): void {}

    public function false(false $value): void {}

    public function true(true $value): void {}

    public function class(Takes $value): void {}

    public function interface(Countable $value): void {}

    /** @param mixed $value  No declared type: what Type::of(null) reads */
    public function untyped($value): void {}
}
