<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravents\Attributes\Publishes;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookRemoved;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookShelved;

/**
 * @property int $id
 * @property string $title
 * @property int $author_id
 */
#[Publishes(BookShelved::class, on: 'created')]
#[Publishes(BookRemoved::class, on: 'deleted')]
final class Book extends Model
{
    public $timestamps = false;

    protected $fillable = ['title', 'author_id'];

    /** In each test's own RefreshDatabase transaction, or on another connection a test names. */
    public static function createTable(?string $connection = null): void
    {
        Schema::connection($connection)->create('books', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->integer('author_id');
        });
    }
}
