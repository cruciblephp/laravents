<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Invalid\Unbuildable;

use Illuminate\Database\Eloquent\Model;
use Laravents\Attributes\Publishes;

/** Publishes an event that needs a $colour the model does not declare, and has no fromModel(). */
#[Publishes(ShelfPainted::class, on: 'updated')]
final class Shelf extends Model
{
    protected $fillable = ['name'];
}
