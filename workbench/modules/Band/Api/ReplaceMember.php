<?php

declare(strict_types=1);

namespace Modules\Band\Api;

use Laravents\Concerns\Sendable;
use Laravents\Contracts\ValidatesPayload;

/**
 * Command band.replace_member: one member leaves the band and another takes their instrument.
 */
final readonly class ReplaceMember implements ValidatesPayload
{
    use Sendable;

    public function __construct(public int $outId, public int $inId) {}

    public static function rules(): array
    {
        return [
            'outId' => 'required|integer|min:1',
            'inId' => 'required|integer|min:1|different:outId',
        ];
    }
}
