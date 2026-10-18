<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;

test('fromRequest() validates with rules() and hydrates the command from the request input', function (): void {
    expect(GiveNickname::fromRequest(Request::create('/nicknames', 'POST', ['memberId' => '5', 'extra' => 'ignored', 'nickname' => 'The Quiet One'])))
        ->toEqual(new GiveNickname(5, 'The Quiet One'));

    $error = caught(ValidationException::class, static fn (): GiveNickname => GiveNickname::fromRequest(Request::create('/nicknames', 'POST', ['memberId' => 0, 'nickname' => 'The Quiet One'])));

    expect($error->errors())->toBe(['memberId' => ['The member id field must be at least 1.']]);
});

test('fromRequest() on a query without rules hydrates from the query string, and a hydration failure is a ValidationException', function (): void {
    expect(GetNickname::fromRequest(Request::create('/nicknames?id=3')))->toEqual(new GetNickname(3));

    $error = caught(ValidationException::class, static fn (): GetNickname => GetNickname::fromRequest(Request::create('/nicknames')));

    expect($error->errors())->toBe(['id' => ['The id field is required.']]);
});
