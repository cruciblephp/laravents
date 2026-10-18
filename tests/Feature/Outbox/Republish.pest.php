<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Outbox\EventPublication;
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

/** A row as if a publisher had committed it and then died before the push, dispatched when given. */
function orphanedPublication(?DateTimeInterface $dispatchedAt): EventPublication
{
    $id = (string) Str::ulid();
    $publication = EventPublication::record(PostedListener::class.'@onPosted', new Message(HandlerKind::Event, Posted::class, new Posted(1), [
        'message_id' => $id,
        'correlation_id' => $id,
    ]));
    $publication->forceFill(['dispatched_at' => $dispatchedAt])->save();

    return $publication->refresh();
}

/** @return list<string> */
function queuedPublicationIds(): array
{
    return array_map(static fn (ExecuteHandlerJob $job): string => $job->publicationId, queuedJobs());
}

test('A4: republish re-dispatches the never-dispatched and the stale row once, leaves the fresh one alone, and the worker completes both', function (): void {
    $never = orphanedPublication(null);
    $stale = orphanedPublication(Date::now()->subHour());
    $fresh = orphanedPublication(Date::now());

    Artisan::call('laravents:republish', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('2 publications would be republished.')->toContain($never->id)->toContain($stale->id)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($never->refresh()->dispatched_at)->toBeNull()
        ->and($stale->refresh()->getRawOriginal('dispatched_at'))->toBe($stale->getRawOriginal('dispatched_at'));

    Artisan::call('laravents:republish');

    expect(Artisan::output())->toContain('2 publications republished.')
        ->and(queuedPublicationIds())->toBe([$never->id, $stale->id])
        ->and(array_map(static fn (ExecuteHandlerJob $job): ?string => $job->queue, queuedJobs()))->toBe(['ledger', 'ledger'])
        ->and($never->refresh()->dispatched_at)->not->toBeNull()
        ->and($fresh->refresh()->getRawOriginal('dispatched_at'))->toBe($fresh->getRawOriginal('dispatched_at'));

    Artisan::call('laravents:republish');

    expect(Artisan::output())->toContain('0 publications republished.')
        ->and(DB::table('jobs')->count())->toBe(2);

    runWorker('ledger');

    expect($never->refresh()->completed_at)->not->toBeNull()
        ->and($stale->refresh()->completed_at)->not->toBeNull()
        ->and($fresh->refresh()->completed_at)->toBeNull();
});

test('a claim made on a stale read loses to the claim that came first', function (): void {
    $read = orphanedPublication(Date::now()->subHour());
    $other = EventPublication::query()->findOrFail($read->id);

    expect($other->claim())->toBeTrue()
        ->and($read->claim())->toBeFalse();

    $completed = orphanedPublication(null);
    $completed->forceFill(['completed_at' => Date::now()])->save();

    expect($completed->claim())->toBeFalse();
});
