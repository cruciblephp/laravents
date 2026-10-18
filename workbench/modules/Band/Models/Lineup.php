<?php

declare(strict_types=1);

namespace Modules\Band\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravents\Attributes\Publishes;
use Modules\Band\Api\Instrument;
use Modules\Band\Api\MemberReplaced;

/**
 * One change to the lineup: who left, who joined, and the instrument that passed between them.
 *
 * @property int $id
 * @property int $out_id
 * @property int $in_id
 * @property Instrument $instrument
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Publishes(MemberReplaced::class, on: 'created')]
final class Lineup extends Model
{
    protected $table = 'lineup_changes';

    protected $fillable = ['out_id', 'in_id', 'instrument'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'out_id' => 'integer',
            'in_id' => 'integer',
            'instrument' => Instrument::class,
        ];
    }
}
