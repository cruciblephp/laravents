<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\HandlerAttributes\Shelf\Handlers;

use Laravents\Attributes\Expose;
use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\HandlerAttributes\Label;
use Laravents\Tests\Fixtures\HandlerAttributes\LoudLabel;

/** Two exposed queries declared out of key order, one not exposed; labels on the class and on one method. */
#[Label('class')]
final class ShelfHandlers
{
    /** @return list<string> */
    #[QueryHandler('shelf.plain')]
    #[Expose]
    public function plain(): array
    {
        return [];
    }

    /** @return list<string> */
    #[QueryHandler('shelf.labelled')]
    #[Expose]
    #[Label('method')]
    #[LoudLabel('loud')]
    public function labelled(): array
    {
        return [];
    }

    /** @return list<string> */
    #[QueryHandler('shelf.hidden')]
    public function hidden(): array
    {
        return [];
    }
}
