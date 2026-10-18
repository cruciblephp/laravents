<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('laravents:list shows the Press listener as async on the press queue, next to the press.print_headline command', function (): void {
    expect(Artisan::call('laravents:list', ['--module' => 'Press']))->toBe(0);

    $lines = explode("\n", Artisan::output());
    $listener = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'HeadlineOnNicknameGiven@__invoke')));
    $command = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'PressHandlers@print')));

    expect($listener)->toHaveCount(1)
        ->and($listener[0])->toContain('async')->toContain('press')
        ->and($command)->toHaveCount(1)
        ->and($command[0])->toContain('press.print_headline')->toContain('sync');
});
