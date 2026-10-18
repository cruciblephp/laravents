<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravents\LaraventsServiceProvider;

/** A scratch database directory, removed after the test. */
function scratchDatabasePath(): string
{
    return sys_get_temp_dir().'/laravents-publish-database';
}

afterEach(function (): void {
    File::deleteDirectory(scratchDatabasePath());
});

test('vendor:publish --tag=laravents-migrations publishes the outbox migration under today\'s timestamp (publishesMigrations)', function (): void {
    // The application's database path is the scratch directory, and the provider boots again so its publish targets
    // are computed against it, as they would be in an application whose database/ lives there.
    app()->useDatabasePath(scratchDatabasePath());
    (new LaraventsServiceProvider(app()))->boot();

    expect(Artisan::call('vendor:publish', ['--tag' => 'laravents-migrations']))->toBe(0);

    $published = array_map(static fn (SplFileInfo $file): string => $file->getFilename(), File::files(scratchDatabasePath().'/migrations'));

    expect($published)->toHaveCount(1)
        ->and($published[0])->toEndWith('_create_laravents_event_publications_table.php')
        ->and($published[0])->toStartWith(date('Y_m_d_'))
        ->and($published[0])->not->toBe('2026_10_15_000100_create_laravents_event_publications_table.php')
        ->and(File::get(scratchDatabasePath().'/migrations/'.$published[0]))
        ->toBe(File::get(dirname(__DIR__, 3).'/database/migrations/2026_10_15_000100_create_laravents_event_publications_table.php'));
});
