<?php

declare(strict_types=1);

namespace Modules\Band\Api;

use Illuminate\Database\Eloquent\Model;
use Laravents\Concerns\Publishable;
use LogicException;

/**
 * Event: a member left the band and another took their instrument. Published when a Lineup change is created.
 */
final readonly class MemberReplaced
{
    use Publishable;

    public function __construct(
        public int $outId,
        public int $inId,
        public Instrument $instrument,
    ) {}

    /** Takes Eloquent's Model, not Lineup, so the Api references nothing of the module outside it. */
    public static function fromModel(Model $model): static
    {
        $instrument = $model->getAttribute('instrument');

        return new self(
            self::id($model->getAttribute('out_id')),
            self::id($model->getAttribute('in_id')),
            $instrument instanceof Instrument ? $instrument : throw new LogicException('A lineup change names an instrument.'),
        );
    }

    private static function id(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new LogicException('A lineup change names its members by integer id.');
    }
}
