<?php

declare(strict_types=1);

namespace Modules\Press\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $nickname_id
 * @property int $member_id
 * @property int $printed_by
 * @property string $correlation_id
 * @property string $publication_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Headline extends Model
{
    protected $fillable = ['nickname_id', 'member_id', 'printed_by', 'correlation_id', 'publication_id'];
}
