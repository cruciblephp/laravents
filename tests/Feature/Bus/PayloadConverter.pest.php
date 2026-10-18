<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Laravents\Bus\ContainerPayloadConverter;
use Laravents\Bus\PayloadConverter;
use Laravents\Tests\Fixtures\Bus\Converter\BookGig;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;

test('the container converter is the bound PayloadConverter', function (): void {
    expect(app(PayloadConverter::class))->toBeInstanceOf(ContainerPayloadConverter::class);
});

test('an instance of the class is returned unchanged', function (): void {
    $command = new GiveNickname(2, 'The Quiet One');

    expect(app(PayloadConverter::class)->convert(GiveNickname::class, $command))->toBe($command);
});

test('an array hydrates a final readonly DTO through constructor promotion', function (): void {
    expect(app(PayloadConverter::class)->convert(GiveNickname::class, ['memberId' => 2, 'ignored' => true, 'nickname' => 'The Quiet One']))
        ->toEqual(new GiveNickname(2, 'The Quiet One'));
});

test('a numeric string is coerced the way PHP coerces a constructor argument', function (): void {
    expect(app(PayloadConverter::class)->convert(GiveNickname::class, ['memberId' => '2', 'nickname' => 'The Quiet One']))->toEqual(new GiveNickname(2, 'The Quiet One'));
});

test('hydration errors become ValidationExceptions naming the parameter', function (): void {
    $converter = app(PayloadConverter::class);

    expect(fn () => $converter->convert(GiveNickname::class, []))
        ->toThrow(ValidationException::class, 'The memberId field is required.')
        ->and(fn () => $converter->convert(GiveNickname::class, ['memberId' => 'x', 'nickname' => 'The Quiet One']))
        ->toThrow(ValidationException::class, 'The memberId field must be of type int.')
        ->and(fn () => $converter->convert(GiveNickname::class, ['memberId' => null, 'nickname' => 'The Quiet One']))
        ->toThrow(ValidationException::class, 'The memberId field must be of type int.');
});

test('only a missing required scalar is reported: the container builds a class, and an optional one has its default', function (): void {
    $e = caught(ValidationException::class, static fn (): object => app(PayloadConverter::class)->convert(BookGig::class, []));

    expect($e->errors())->toBe(['venueId' => ['The venueId field is required.']]);
});

test('an object of another class is a programmer error, not a 422', function (): void {
    app(PayloadConverter::class)->convert(GiveNickname::class, new GetNickname(1));
})->throws(InvalidArgumentException::class, 'Expected '.GiveNickname::class.', got '.GetNickname::class.'.');
