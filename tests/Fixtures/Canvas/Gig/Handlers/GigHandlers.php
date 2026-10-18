<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Canvas\Gig\Handlers;

use Laravents\Attributes\QueryHandler;
use Laravents\Tests\Fixtures\Canvas\Gig\Api\FindGig;
use Laravents\Tests\Fixtures\Canvas\Gig\Api\GigView;

final class GigHandlers
{
    #[QueryHandler]
    public function find(FindGig $query): GigView
    {
        return new GigView($query->id);
    }
}
