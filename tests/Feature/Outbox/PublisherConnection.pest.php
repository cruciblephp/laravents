<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravents\Bus\Message;
use Laravents\Bus\ModelEvents;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Adjust;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Clear;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Remit;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Transfer;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\HandWrittenHandlers;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\LedgerHandlers;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\PostedListener;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\TransferHandlers;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookShelved;
use Laravents\Tests\Fixtures\Publishes\Library\Handlers\ShelvedArchiver;
use Laravents\Tests\Fixtures\Publishes\Library\Models\Book;
use Laravents\Tests\Fixtures\Recorder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();

    // A second database, in memory, with the outbox table migrated onto it as an application would.
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    Artisan::call('migrate', [
        '--database' => 'other',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
    ]);

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Async'))->build([
        HandWrittenHandlers::class,
        LedgerHandlers::class,
        PostedListener::class,
        TransferHandlers::class,
    ]));
});

afterEach(function (): void {
    DB::purge('other');
});

function publicationsOn(?string $connection): int
{
    return EventPublication::on($connection)->count();
}

test('a publisher in #[Transactional(connection: other)] that rolls back leaves no outbox row on either connection, and no job', function (): void {
    expect(fn (): mixed => Laravents::command(new Transfer(7, fail: true)))->toThrow(RuntimeException::class, 'Transfer failed.');

    expect(publicationsOn('other'))->toBe(0)
        ->and(publicationsOn(null))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Recorder::calls())->toContain(['transfer: other transaction level', 1]);

    runWorker('ledger');

    expect(Recorder::handlers())->not->toContain('onPosted');
});

test('a publisher in #[Transactional(connection: other)] writes its row on other, the job carries other, and the worker completes it', function (): void {
    Laravents::command(new Transfer(8));

    $publication = EventPublication::on('other')->sole();
    $jobs = queuedJobs();

    expect(publicationsOn(null))->toBe(0)
        ->and($publication->listener)->toBe(PostedListener::class.'@onPosted')
        ->and($publication->completed_at)->toBeNull()
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->publicationId)->toBe($publication->id)
        ->and($jobs[0]->publicationConnection)->toBe('other');

    runWorker('ledger');

    expect(Recorder::calls())->toContain(['onPosted: tenant', null])
        ->and($publication->refresh()->completed_at)->not->toBeNull();
});

test('republish finds a stale row on every connection listed in laravents.outbox.connections', function (): void {
    $id = (string) Str::ulid();
    $publication = DB::connection('other')->transaction(static fn (): EventPublication => EventPublication::record(PostedListener::class.'@onPosted', new Message(HandlerKind::Event, Posted::class, new Posted(9), [
        'message_id' => $id,
        'correlation_id' => $id,
    ])));

    $publication->forceFill(['dispatched_at' => null])->save();

    expect($publication->getConnectionName())->toBe('other');

    Artisan::call('laravents:republish');
    expect(Artisan::output())->toContain('0 publications republished.');

    config(['laravents.outbox.connections' => [null, 'other']]);
    Artisan::call('laravents:republish');

    expect(Artisan::output())->toContain('1 publication republished.')
        ->and(queuedJobs()[0]->publicationConnection)->toBe('other');

    runWorker('ledger');

    expect($publication->refresh()->completed_at)->not->toBeNull();
});

test('a handler with no #[Transactional] publishing inside DB::connection(other)->transaction() writes its row on other; when the closure throws, no row anywhere and no job', function (): void {
    Laravents::command(new Remit(4));

    $publication = EventPublication::on('other')->sole();

    expect(publicationsOn(null))->toBe(0)
        ->and(queuedJobs())->toHaveCount(1)
        ->and(queuedJobs()[0]->publicationConnection)->toBe('other');

    runWorker('ledger');

    expect($publication->refresh()->completed_at)->not->toBeNull();

    EventPublication::on('other')->delete();
    Recorder::reset();

    expect(fn (): mixed => Laravents::command(new Remit(5, fail: true)))->toThrow(RuntimeException::class, 'Remit failed.');

    expect(publicationsOn('other'))->toBe(0)
        ->and(publicationsOn(null))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    runWorker('ledger');

    expect(Recorder::handlers())->not->toContain('onPosted');
});

test('#[Transactional] on the default connection around a hand-written other transaction: the innermost wins, the row is on other', function (): void {
    Laravents::command(new Clear(6));

    expect(EventPublication::on('other')->sole()->listener)->toBe(PostedListener::class.'@onPosted')
        ->and(publicationsOn(null))->toBe(0)
        ->and(queuedJobs())->toHaveCount(1)
        ->and(queuedJobs()[0]->publicationConnection)->toBe('other');
});

test('when the hand-written other transaction commits and the outer command then throws, the other row stays; the job was pushed at other\'s commit, into the jobs table on the default connection, and rolled back with it', function (): void {
    $queued = 0;
    Event::listen(JobQueued::class, static function () use (&$queued): void {
        $queued++;
    });

    expect(fn (): mixed => Laravents::command(new Clear(7, fail: true)))->toThrow(RuntimeException::class, 'Clear failed.');

    $publication = EventPublication::on('other')->sole();

    // ShouldQueueAfterCommit attached the push to the innermost transaction (other), not the outermost one:
    // it ran when other committed, while the default transaction was still open.
    expect($queued)->toBe(1)
        ->and(Recorder::calls())->toContain(['clear: jobs after other committed', 1])
        // The database queue's jobs table is on the default connection, so the push rolled back with the command.
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(publicationsOn(null))->toBe(0)
        ->and($publication->completed_at)->toBeNull()
        ->and($publication->dispatched_at)->not->toBeNull();

    // The row is committed with its transaction; republish delivers it once it is stale, as for any lost push.
    config(['laravents.outbox.connections' => [null, 'other'], 'laravents.outbox.republish_after' => 0]);
    $this->travel(1)->seconds();
    Artisan::call('laravents:republish');
    runWorker('ledger');

    expect(Recorder::handlers())->toContain('onPosted')
        ->and($publication->refresh()->completed_at)->not->toBeNull();
});

test('a nested transaction on the same connection (level 2) inside #[Transactional] writes on that connection and rolls back with the outer one', function (): void {
    // RefreshDatabase's testing manager runs after-commit callbacks at level 1 on every connection, as if each
    // were wrapped in the test's transaction; `other` is not, so this test uses the manager an application has.
    $manager = new DatabaseTransactionsManager;
    app()->instance('db.transactions', $manager);
    DB::connection('other')->setTransactionManager($manager);

    Laravents::command(new Adjust(8));

    expect(Recorder::calls())->toContain(['adjust: other transaction level', 2])
        ->and(EventPublication::on('other')->sole()->listener)->toBe(PostedListener::class.'@onPosted')
        ->and(publicationsOn(null))->toBe(0)
        ->and(queuedJobs()[0]->publicationConnection)->toBe('other');

    EventPublication::on('other')->delete();
    DB::table('jobs')->delete();

    expect(fn (): mixed => Laravents::command(new Adjust(9, fail: true)))->toThrow(RuntimeException::class, 'Adjust failed.');

    expect(publicationsOn('other'))->toBe(0)
        ->and(publicationsOn(null))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('the model-publication rollback on other: a Book created in a hand-written other transaction writes its row there, and a rollback leaves none', function (): void {
    Book::createTable('other');
    $registry = (new RegistryBuilder('Laravents\Tests\Fixtures\Publishes'))->build([Book::class, ShelvedArchiver::class]);
    app()->instance(Registry::class, $registry);
    app(ModelEvents::class)->subscribe($registry, app('events'));

    DB::connection('other')->transaction(static function (): void {
        Book::on('other')->create(['title' => 'Kept', 'author_id' => 1]);
    });

    expect(EventPublication::on('other')->sole()->event_class)->toBe(BookShelved::class)
        ->and(publicationsOn(null))->toBe(0)
        ->and(queuedJobs()[0]->publicationConnection)->toBe('other');

    EventPublication::on('other')->delete();
    DB::table('jobs')->delete();

    expect(fn () => DB::connection('other')->transaction(static function (): void {
        Book::on('other')->create(['title' => 'Dropped', 'author_id' => 2]);
        expect(EventPublication::on('other')->count())->toBe(1);

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class, 'Rolled back.');

    expect(Book::on('other')->count())->toBe(1)
        ->and(publicationsOn('other'))->toBe(0)
        ->and(publicationsOn(null))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});
