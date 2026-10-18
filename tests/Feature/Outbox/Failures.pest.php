<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Settled;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\SettledListener;
use Laravents\Tests\Fixtures\Recorder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
    SettledListener::$failures = 0;
    config(['logging.default' => 'null']);

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Async'))->build([SettledListener::class]));
});

/** @return list<mixed> What each attempt saw as its row's completed_at when it started. */
function settledAttempts(): array
{
    return array_values(array_map(
        static fn (array $call): mixed => $call[1],
        array_filter(Recorder::calls(), static fn (array $call): bool => $call[0] === 'onSettled: completed_at'),
    ));
}

test('A3: a listener that fails once is retried by the queue and completes; at the second attempt the row was still incomplete', function (): void {
    config(['laravents.async.tries' => 3]);
    SettledListener::$failures = 1;

    Laravents::publish(new Settled(10));
    runWorker('ledger');

    expect(settledAttempts())->toBe([null, null])
        ->and(EventPublication::query()->sole()->completed_at)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('A5: a listener that always fails exhausts its tries into failed_jobs; republish leaves the recent row alone and queue:retry completes it', function (): void {
    config(['laravents.async.tries' => 2]);
    SettledListener::$failures = PHP_INT_MAX;

    Laravents::publish(new Settled(10));
    runWorker('ledger');

    expect(settledAttempts())->toHaveCount(2)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(EventPublication::query()->sole()->completed_at)->toBeNull();

    Artisan::call('laravents:republish');

    expect(Artisan::output())->toContain('0 publications republished.')
        ->and(DB::table('jobs')->count())->toBe(0);

    SettledListener::$failures = 0;
    $failed = DB::table('failed_jobs')->value('uuid');
    $this->assertIsString($failed);
    Artisan::call('queue:retry', ['id' => [$failed]]);
    runWorker('ledger');

    expect(settledAttempts())->toHaveCount(3)
        ->and(EventPublication::query()->sole()->completed_at)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
