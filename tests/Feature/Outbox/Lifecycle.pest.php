<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Book;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
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

test('A2: a transactional command that publishes an async event and then throws leaves no data, no row and no job, and the listener never runs', function (): void {
    expect(fn (): mixed => Laravents::command(new Book(5, fail: true)))->toThrow(RuntimeException::class, 'Booking failed.');

    expect(Entries::count())->toBe(0)
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    runWorker('ledger');

    expect(Recorder::handlers())->toContain('post')
        ->not->toContain('onPosted');
});

test('outside a transaction the row is written and the job pushed at once', function (): void {
    // Under RefreshDatabase the only open transaction is the test's own, which after-commit work treats as none.
    $level = DB::transactionLevel();

    Laravents::publish(new Posted(3));

    $publication = EventPublication::query()->sole();
    $jobs = queuedJobs();

    expect(DB::transactionLevel())->toBe($level)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->publicationId)->toBe($publication->id)
        ->and($publication->listener)->toBe(PostedListener::class.'@onPosted');
});

test('publication_id is reserved: a caller cannot pass it', function (): void {
    expect(fn () => Laravents::withHeader('publication_id', 'p-1')->publish(new Posted(1)))
        ->toThrow(InvalidArgumentException::class, 'Header [publication_id] is set by Laravents and cannot be passed.');

    expect(EventPublication::query()->count())->toBe(0);
});

test('custom headers survive the queue: the replayed handler reads tenant through #[Header]', function (): void {
    Laravents::withHeader('tenant', 'acme')->publish(new Posted(4));

    expect(EventPublication::query()->sole()->headers['tenant'] ?? null)->toBe('acme');

    runWorker('ledger');

    expect(Recorder::calls())->toContain(['onPosted: tenant', 'acme'])
        ->and(EventPublication::query()->sole()->completed_at)->not->toBeNull();
});
