<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Band\Api\Instrument;
use Workbench\Database\Factories\UserFactory;

/**
 * @property bool $is_member A member of the band, who may give nicknames; everyone else is a fan.
 * @property Instrument|null $instrument What a member plays.
 */
#[Fillable(['name', 'email', 'password', 'is_member', 'instrument'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The workbench's factory, outside the Database\Factories namespace Laravel guesses. */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_member' => 'boolean',
            'instrument' => Instrument::class,
        ];
    }
}
