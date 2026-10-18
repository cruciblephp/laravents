<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Book;
use Laravents\Tests\Fixtures\Async\Ledger\Entries;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\LedgerHandlers;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\PostedListener;
use Laravents\Tests\Fixtures\Recorder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
    Entries::createTable();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Async'))->build([
        LedgerHandlers::class,
        PostedListener::class,
    ]));
});

/** @return array<string, mixed> */
function bookRecords(): array
{
    $records = [];

    foreach (Recorder::calls() as [$handler, $payload]) {
        if (str_starts_with($handler, 'book: ')) {
            $records[substr($handler, 6)] = $payload;
        }
    }

    return $records;
}

test('A9: an async event published by a nested transactional command is queued only after the outer transaction commits', function (): void {
    $outside = DB::transactionLevel();

    Laravents::command(new Book(5));

    expect(bookRecords())->toBe(['transaction level' => $outside + 1, 'jobs while open' => 0])
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(publicationOf(queuedJobs()[0])->listener)->toBe(PostedListener::class.'@onPosted')
        ->and(Entries::count())->toBe(1);
});

test('A9: when the outer transaction rolls back after the inner command returned, nothing is queued and nothing is written', function (): void {
    expect(fn (): mixed => Laravents::command(new Book(5, fail: true)))->toThrow(RuntimeException::class, 'Booking failed.');

    expect(bookRecords())->toHaveKey('jobs while open', 0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(Entries::count())->toBe(0);

    runWorker('ledger');

    expect(Recorder::handlers())->not->toContain('onPosted');
});
