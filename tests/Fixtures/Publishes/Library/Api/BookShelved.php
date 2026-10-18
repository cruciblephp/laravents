<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Library\Api;

/** Built from the model's attributes: no fromModel(), parameters named after attributes (authorId is author_id). */
final readonly class BookShelved
{
    public function __construct(
        public int $id,
        public string $title,
        public int $authorId,
        public string $edition = 'first',
    ) {}
}
