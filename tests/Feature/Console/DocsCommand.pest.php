<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;

afterEach(function (): void {
    File::deleteDirectory(docsPath());
    File::deleteDirectory(sys_get_temp_dir().'/laravents-docs-base');
});

/** A scratch directory for the generated files, removed after each test. */
function docsPath(): string
{
    return sys_get_temp_dir().'/laravents-docs';
}

/**
 * The lines of one generated file.
 *
 * @return list<string>
 */
function docLines(string $directory, string $file): array
{
    return explode("\n", (string) file_get_contents($directory.'/'.$file));
}

/** @return array<string, string> file name => sha1 */
function checksums(string $directory): array
{
    $sums = [];

    foreach (File::files($directory) as $file) {
        $sums[$file->getFilename()] = sha1_file($file->getPathname()) ?: '';
    }

    ksort($sums);

    return $sums;
}

test('README.md lists the modules and draws the dependency graph', function (): void {
    expect(Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]))->toBe(0)
        ->and(array_keys(checksums(docsPath())))->toBe(['Band.md', 'FanClub.md', 'Press.md', 'README.md']);

    expect(docLines(docsPath(), 'README.md'))->toContain(
        '# Modules',
        '| Module | Namespace | Commands | Queries | Events published | Events consumed | Depends on |',
        '| [Band](Band.md) | `Modules\Band` | 2 | 1 | 2 | 0 | - |',
        '| [FanClub](FanClub.md) | `Modules\FanClub` | 1 | 0 | 0 | 3 | - |',
        '| [Press](Press.md) | `Modules\Press` | 1 | 0 | 0 | 1 | Band |',
        '## Dependencies',
        '```mermaid',
        'graph LR',
        '    FanClub',
        '    Press --> Band',
    );
});

test('Band.md is the Band canvas as tables', function (): void {
    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);
    $band = docLines(docsPath(), 'Band.md');

    expect($band)->toContain(
        '# Band',
        'Namespace `Modules\Band`. [All modules](README.md)',
        '## Commands',
        '| `band.give_nickname` | `Modules\Band\Handlers\BandHandlers@give` | `Modules\Band\Api\GiveNickname` | Authorize, Transactional | `POST /laravents/commands/band.give_nickname` |',
        '## Queries',
        '| `band.get_nickname` | `Modules\Band\Handlers\BandHandlers@get` | `Modules\Band\Api\GetNickname` | - | `GET /laravents/queries/band.get_nickname` |',
        '## Events published',
        '| `Modules\Band\Api\NicknameGiven` | `Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke` | Press | async | press |',
        '## Model publications',
        '| `Modules\Band\Api\NicknameGiven` | `Modules\Band\Models\Nickname` | created |',
        '## HTTP endpoints',
        '| `POST /laravents/commands/band.give_nickname` | `Modules\Band\Handlers\BandHandlers@give` |',
        '| `GET /laravents/queries/band.get_nickname` | `Modules\Band\Handlers\BandHandlers@get` |',
    );

    // The sections in canvas order, and an empty one says so.
    $sections = array_values(array_filter($band, static fn (string $line): bool => str_starts_with($line, '## ')));
    expect($sections)->toBe(['## Commands', '## Queries', '## Events published', '## Events consumed', '## Model publications', '## HTTP endpoints', '## Dependencies'])
        ->and(array_slice($band, (int) array_search('## Events consumed', $band, true), 3))->toBe(['## Events consumed', '', 'None.']);
});

test('Press.md lists what it consumes from Band and its dependency on Band; FanClub.md what it consumes from Band and from Laravel', function (): void {
    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);

    expect(docLines(docsPath(), 'Press.md'))->toContain(
        '| `press.print_headline` | `Modules\Press\Handlers\PressHandlers@print` | `Modules\Press\Api\PrintHeadline` | Authorize, Transactional | - |',
        '| `Modules\Band\Api\NicknameGiven` | `Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke` | Band | async | press |',
        '| [Band](Band.md) | 1 | 0 |',
    )->and(docLines(docsPath(), 'FanClub.md'))->toContain(
        '| `Illuminate\Auth\Events\Registered` | `Modules\FanClub\Handlers\WelcomeOnRegistered@__invoke` | Laravel | async | fan_club |',
    );
});

test('the pages list a pattern listener under the event it covers and as consumed from the event\'s module', function (): void {
    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);

    expect(docLines(docsPath(), 'Band.md'))
        ->toContain('| `Modules\Band\Api\NicknameGiven` | `Modules\FanClub\Handlers\ArchiveBandEvents@__invoke` | FanClub | sync | - |')
        ->and(docLines(docsPath(), 'FanClub.md'))
        ->toContain('| `Modules\Band\Api\NicknameGiven` | `Modules\FanClub\Handlers\ArchiveBandEvents@__invoke` | Band | sync | - |');
});

test('an edge that violates direction is dashed and labelled', function (): void {
    $path = dirname(__DIR__, 2).'/Fixtures/Verify/Direction';
    config(['laravents.paths' => [$path], 'laravents.modules.namespace' => 'Laravents\Tests\Fixtures\Verify\Direction']);
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Verify\Direction'))->build(ClassDiscovery::in([$path])));

    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);

    expect(docLines(docsPath(), 'README.md'))->toContain(
        '    Billing -. "1 outside Api" .-> Orders',
        'A dashed edge reaches outside the other module\'s Api: `php artisan laravents:verify` reports it as a direction violation.',
    )->and(docLines(docsPath(), 'Billing.md'))->toContain('| [Orders](Orders.md) | 1 | 1 |');
});

test('the path comes from --path (under the base path, or as given with --realpath), else laravents.docs.path', function (): void {
    // A throwaway base path: a run killed mid-test leaves nothing in the repository.
    app()->setBasePath(sys_get_temp_dir().'/laravents-docs-base');

    Artisan::call('laravents:docs', ['--path' => 'storage/framework/testing/laravents-docs/option']);
    expect(base_path('storage/framework/testing/laravents-docs/option/README.md'))->toBeFile();

    Artisan::call('laravents:docs', ['--path' => '/storage/framework/testing/laravents-docs/rooted', '--check' => true]);
    expect(Artisan::output())->toContain('[storage/framework/testing/laravents-docs/rooted]');

    config(['laravents.docs.path' => '']);
    Artisan::call('laravents:docs', ['--check' => true]);
    expect(Artisan::output())->toContain('[docs/laravents]');

    config(['laravents.docs.path' => docsPath()]);
    Artisan::call('laravents:docs');
    expect(docsPath().'/Band.md')->toBeFile()
        ->and(Artisan::output())->toContain('Module docs for 3 modules written to');
});

test('the default path is docs/laravents under the base path', function (): void {
    expect(config('laravents.docs.path'))->toBe(base_path('docs/laravents'));
});

test('running it twice changes nothing', function (): void {
    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);
    $first = checksums(docsPath());
    $mtime = filemtime(docsPath().'/README.md');

    expect(Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]))->toBe(0)
        ->and(checksums(docsPath()))->toBe($first)
        ->and(filemtime(docsPath().'/README.md'))->toBe($mtime)
        ->and(substr_count(Artisan::output(), 'unchanged'))->toBe(4);
});

/**
 * The rows of a laravents:docs run, trimmed, with the dot leader and any run of spaces folded into one space.
 *
 * @return list<string>
 */
function docRows(): array
{
    $rows = array_map(
        static fn (string $line): string => trim((string) preg_replace(['/ \.{2,}( |$)/', '/ {2,}/'], ' ', $line)),
        explode("\n", Artisan::output()),
    );

    return array_values(array_filter($rows, static fn (string $row): bool => $row !== ''));
}

test('--check on a stale and a missing file exits 1, names both, and writes nothing', function (): void {
    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);
    File::put(docsPath().'/Band.md', "# Band\n\nEdited by hand.\n");
    File::delete(docsPath().'/Press.md');
    $before = checksums(docsPath());

    expect(Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true, '--check' => true]))->toBe(1)
        ->and(docRows())->toBe([
            docsPath().'/README.md current',
            docsPath().'/Band.md stale',
            docsPath().'/FanClub.md current',
            docsPath().'/Press.md missing',
            'ERROR 2 files in ['.docsPath().'] are out of date. Run php artisan laravents:docs.',
        ])
        ->and(checksums(docsPath()))->toBe($before)
        ->and(docsPath().'/Press.md')->not->toBeFile();
});

test('--check on a directory that does not exist reports every file missing and creates nothing', function (): void {
    expect(Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true, '--check' => true]))->toBe(1)
        ->and(docRows())->toContain(docsPath().'/README.md missing', docsPath().'/Band.md missing', docsPath().'/FanClub.md missing', docsPath().'/Press.md missing')
        ->and(docsPath())->not->toBeDirectory();
});

test('--check on current docs exits 0 and says so, leaving every file as it was', function (): void {
    Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true]);
    $before = checksums(docsPath());
    $mtime = filemtime(docsPath().'/README.md');

    expect(Artisan::call('laravents:docs', ['--path' => docsPath(), '--realpath' => true, '--check' => true]))->toBe(0)
        ->and(docRows())->toBe([
            docsPath().'/README.md current',
            docsPath().'/Band.md current',
            docsPath().'/FanClub.md current',
            docsPath().'/Press.md current',
            'INFO Module docs in ['.docsPath().'] are current.',
        ])
        ->and(checksums(docsPath()))->toBe($before)
        ->and(filemtime(docsPath().'/README.md'))->toBe($mtime);
});

test('--check on the committed docs/laravents of the repository exits 0', function (): void {
    $committed = dirname(__DIR__, 3).'/docs/laravents';

    expect(Artisan::call('laravents:docs', ['--path' => $committed, '--realpath' => true, '--check' => true]))->toBe(0)
        ->and(docRows())->toContain('INFO Module docs in ['.$committed.'] are current.');
});
