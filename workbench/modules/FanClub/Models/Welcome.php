<?php

declare(strict_types=1);

namespace Modules\FanClub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $sent_by
 * @property string $correlation_id
 * @property string $publication_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Welcome extends Model
{
    protected $fillable = ['user_id', 'sent_by', 'correlation_id', 'publication_id'];
}
