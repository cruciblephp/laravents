<?php

declare(strict_types=1);

namespace Modules\Band\Api;

use Laravents\Concerns\Sendable;
use Laravents\Contracts\ValidatesPayload;

/**
 * Command band.give_nickname: the actor gives another member a nickname.
 */
final readonly class GiveNickname implements ValidatesPayload
{
    use Sendable;

    public function __construct(public int $memberId, public string $nickname) {}

    public static function rules(): array
    {
        return [
            'memberId' => 'required|integer|min:1',
            'nickname' => 'required|string|max:40',
        ];
    }
}
