<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\Band\Api\NicknameGiven;

test('laravents:list shows the Band, Press and FanClub handlers', function (): void {
    expect(Artisan::call('laravents:list'))->toBe(0);

    expect(Artisan::output())
        ->toContain('band.give_nickname')
        ->toContain('Modules\Band\Handlers\BandHandlers@give')
        ->toContain('band.get_nickname')
        ->toContain('Modules\Band\Handlers\BandHandlers@get')
        ->toContain(NicknameGiven::class)
        ->toContain('Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke')
        ->toContain('async')
        ->toContain('press');
});

test('laravents:list --module filters by the handler module', function (): void {
    Artisan::call('laravents:list', ['--module' => 'Press']);

    expect(Artisan::output())
        ->toContain('HeadlineOnNicknameGiven')
        ->not->toContain('band.give_nickname');
});

test('laravents:list prints a pattern handler with its pattern as the key and no message', function (): void {
    Artisan::call('laravents:list', ['--module' => 'FanClub']);

    $row = array_values(array_filter(
        explode("\n", Artisan::output()),
        static fn (string $line): bool => str_contains($line, 'ArchiveBandEvents'),
    ));

    expect($row)->toHaveCount(1)
        ->and(array_map(trim(...), explode('|', trim($row[0], ' |'))))->toBe([
            'Event', 'band.*', '-', 'Modules\FanClub\Handlers\ArchiveBandEvents@__invoke', 'sync', '-', '-',
        ]);
});
