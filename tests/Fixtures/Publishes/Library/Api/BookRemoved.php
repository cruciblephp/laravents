<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Library\Api;

use Illuminate\Database\Eloquent\Model;

/** Built by fromModel(), which wins over the constructor. */
final readonly class BookRemoved
{
    public function __construct(public string $label) {}

    public static function fromModel(Model $model): static
    {
        $title = $model->getAttribute('title');

        return new self('removed: '.(is_string($title) ? $title : '?'));
    }
}
