<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Http\Kiosk\Api;

use Laravents\Contracts\ValidatesPayload;

final readonly class StampTicket implements ValidatesPayload
{
    public function __construct(public string $label) {}

    public static function rules(): array
    {
        return ['label' => 'required|string'];
    }
}
