<?php

declare(strict_types=1);

namespace Modules\Band\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravents\Attributes\Publishes;
use Modules\Band\Api\NicknameGiven;

/**
 * @property int $id
 * @property int $given_by
 * @property int $member_id
 * @property string $nickname
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Publishes(NicknameGiven::class, on: 'created')]
final class Nickname extends Model
{
    protected $fillable = ['given_by', 'member_id', 'nickname'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'given_by' => 'integer',
            'member_id' => 'integer',
        ];
    }
}
