<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Http\ExposedDispatcher;
use Laravents\Tests\Fixtures\Http\Kiosk\Handlers\KioskHandlers;
use Laravents\Tests\Fixtures\Recorder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    Recorder::reset();
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Http'))->build([KioskHandlers::class]));
});

test('an exposed command and an exposed query return the handler\'s raw result, and the adapter\'s correlation_id reaches the handler', function (): void {
    $dispatcher = app(ExposedDispatcher::class);

    expect($dispatcher->command('kiosk.stamp_ticket', ['label' => 'A-1'], ['correlation_id' => 'req-7']))
        ->toBe(['label' => 'A-1', 'correlation_id' => 'req-7'])
        ->and($dispatcher->query('kiosk.find_stamp', ['id' => '4'], ['correlation_id' => 'req-8']))
        ->toEqual((object) ['id' => 4, 'correlation_id' => 'req-8']);
});

test('an unexposed handler, an unknown key and a key of the other kind are each a NotFoundHttpException, and the handler never runs', function (string $kind, string $key): void {
    $dispatcher = app(ExposedDispatcher::class);

    expect(fn (): mixed => $kind === 'command' ? $dispatcher->command($key, ['id' => 1]) : $dispatcher->query($key, ['id' => 1]))
        ->toThrow(NotFoundHttpException::class)
        ->and(Recorder::calls())->toBe([]);
})->with([
    'unexposed' => ['command', 'kiosk.shred_ticket'],
    'unknown' => ['command', 'kiosk.nothing'],
    'a query key given to command()' => ['command', 'kiosk.find_stamp'],
    'a command key given to query()' => ['query', 'kiosk.stamp_ticket'],
]);

test('a missing field is a ValidationException naming it', function (): void {
    try {
        app(ExposedDispatcher::class)->command('kiosk.stamp_ticket', []);
        $this->fail('No ValidationException was thrown.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['label']);
    }
});

test('a reserved header is refused as the facade refuses it', function (): void {
    expect(fn (): mixed => app(ExposedDispatcher::class)->command('kiosk.stamp_ticket', ['label' => 'A-1'], ['message_id' => 'forged']))
        ->toThrow(InvalidArgumentException::class, 'Header [message_id] is set by Laravents and cannot be passed.');
});
