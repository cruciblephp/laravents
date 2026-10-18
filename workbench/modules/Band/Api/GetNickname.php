<?php

declare(strict_types=1);

namespace Modules\Band\Api;

use Laravents\Concerns\Askable;

/**
 * Query band.get_nickname: one nickname by id.
 */
final readonly class GetNickname
{
    use Askable;

    public function __construct(public int $id) {}
}
