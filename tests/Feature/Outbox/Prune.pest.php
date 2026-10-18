<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerKind;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\PostedListener;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    Artisan::call('migrate', [
        '--database' => 'other',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
    ]);
});

afterEach(function (): void {
    DB::purge('other');
});

/** A row on the connection, completed that many hours ago, or never when null. */
function prunablePublication(?int $completedHoursAgo, ?string $connection = null): EventPublication
{
    $record = static function (): EventPublication {
        $id = (string) Str::ulid();

        return EventPublication::record(PostedListener::class.'@onPosted', new Message(HandlerKind::Event, Posted::class, new Posted(1), [
            'message_id' => $id,
            'correlation_id' => $id,
        ]));
    };

    $publication = $connection === null ? $record() : DB::connection($connection)->transaction($record);
    $publication->forceFill([
        'published_at' => Date::now()->subDays(400),
        'completed_at' => $completedHoursAgo === null ? null : Date::now()->subHours($completedHoursAgo),
    ])->save();

    return $publication;
}

/** model:prune for the outbox model, as the manual tells an application to schedule it. */
function pruneOutbox(): string
{
    Artisan::call('model:prune', ['--model' => [EventPublication::class]]);

    return Artisan::output();
}

test('with no prune_after every row is kept, a year-old completed one too', function (): void {
    prunablePublication(24 * 365);

    pruneOutbox();

    expect(EventPublication::query()->count())->toBe(1);
});

test('with prune_after, model:prune deletes the rows completed longer ago and keeps the rest', function (): void {
    config(['laravents.outbox.prune_after' => 720]);
    $old = prunablePublication(721);
    $recent = prunablePublication(719);
    $incomplete = prunablePublication(null);

    $output = pruneOutbox();

    expect(EventPublication::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $incomplete->id])->sort()->values()->all())
        ->and(EventPublication::query()->find($old->id))->toBeNull()
        ->and($output)->toContain(EventPublication::class)->toContain('1 records');
});

test('the value is read as a number, as an environment variable gives it', function (): void {
    config(['laravents.outbox.prune_after' => '720']);
    prunablePublication(721);

    pruneOutbox();

    expect(EventPublication::query()->count())->toBe(0);
});

test('laravents.outbox.connections is read as connection names, null for the default, each once', function (mixed $configured, array $connections): void {
    config(['laravents.outbox.connections' => $configured]);

    expect(EventPublication::outboxConnections())->toBe($connections);
})->with([
    'unset' => [null, [null]],
    'empty' => [[], [null]],
    'the default spelled three ways, and a repeat' => [[null, '', 'other', 'other', 0], [null, 'other']],
]);

test('every connection in laravents.outbox.connections is pruned, as republish searches them all', function (): void {
    config(['laravents.outbox.prune_after' => 720, 'laravents.outbox.connections' => [null, 'other']]);
    prunablePublication(721);
    $other = prunablePublication(721, 'other');
    prunablePublication(1, 'other');

    expect($other->getConnectionName())->toBe('other');

    pruneOutbox();

    expect(EventPublication::query()->count())->toBe(0)
        ->and(EventPublication::on('other')->count())->toBe(1);
});

test('a connection not listed is left alone', function (): void {
    config(['laravents.outbox.prune_after' => 720]);
    prunablePublication(721, 'other');

    pruneOutbox();

    expect(EventPublication::on('other')->count())->toBe(1);
});
