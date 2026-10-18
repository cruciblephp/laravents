<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;
use Laravents\LaraventsServiceProvider;

afterEach(function (): void {
    app(RegistryCache::class)->clear();
});

/**
 * The rows of `about --only=laravents`, trimmed, with the dot leader and any run of spaces folded into one space.
 *
 * @return list<string>
 */
function aboutRows(): array
{
    expect(Artisan::call('about', ['--only' => 'laravents']))->toBe(0);

    $rows = array_map(
        static fn (string $line): string => trim((string) preg_replace(['/ \.{2,}( |$)/', '/ {2,}/'], ' ', $line)),
        explode("\n", Artisan::output()),
    );

    return array_values(array_filter($rows, static fn (string $row): bool => $row !== ''));
}

test('about reports the workbench: registry, modules, handlers, model publications, HTTP gateway and outbox connections', function (): void {
    expect(aboutRows())->toBe([
        'Laravents',
        'HTTP gateway Enabled, /laravents',
        'Handlers 4 commands / 1 query / 3 events',
        'Model publications 2',
        'Modules 3 (Band, FanClub, Press)',
        'Outbox connections default',
        'Registry Scanned',
    ]);
});

test('about says Cached once laravents:cache wrote the registry, and follows the HTTP and outbox config', function (): void {
    Artisan::call('laravents:cache');
    config([
        'laravents.http.enabled' => false,
        'laravents.outbox.connections' => [null, 'other'],
    ]);

    expect(aboutRows())->toContain('Registry Cached', 'HTTP gateway Disabled', 'Outbox connections default, other');
});

test('about names the disabled modules, which no other row shows, and has no such row when none is', function (): void {
    config(['laravents.modules.disabled' => ['Press']]);
    app()->instance(Registry::class, LaraventsServiceProvider::scan(config()));

    expect(aboutRows())->toContain('Disabled modules Press', 'Modules 2 (Band, FanClub)');

    config(['laravents.modules.disabled' => []]);

    expect(implode("\n", aboutRows()))->not->toContain('Disabled modules');
});

test('about --json carries the same values under a laravents key', function (): void {
    expect(Artisan::call('about', ['--only' => 'laravents', '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['laravents' => [
            'registry' => 'Scanned',
            'modules' => '3 (Band, FanClub, Press)',
            'handlers' => '4 commands / 1 query / 3 events',
            'model_publications' => '2',
            'http_gateway' => 'Enabled, /laravents',
            'outbox_connections' => 'default',
        ]]);
});

test('about with a broken registry prints the error as one row, keeps the config rows and exits 0', function (): void {
    config([
        'laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Discovery/Invalid'],
        'laravents.modules.namespace' => 'Laravents\Tests\Fixtures\Discovery',
    ]);
    app()->forgetInstance(Registry::class);

    $rows = aboutRows();

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe('Laravents')
        ->and($rows[1])->toBe('HTTP gateway Enabled, /laravents')
        ->and($rows[2])->toBe('Outbox connections default')
        ->and($rows[3])->toStartWith('Registry ERROR ')
        ->and($rows[3])->toContain('#[Asynchronous] is only allowed on an event handler')
        ->and(implode("\n", $rows))->not->toContain('Stack trace');
});
