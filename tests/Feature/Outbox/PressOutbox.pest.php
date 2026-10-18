<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Outbox\EventPublication;
use Modules\Band\Api\GiveNickname;
use Modules\Press\Handlers\HeadlineOnNicknameGiven;
use Modules\Press\Models\Headline;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

test('A1: band.give_nickname writes an incomplete outbox row for the Press listener; the worker completes it and the headline carries its publication_id', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $nicknameId = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    $publication = EventPublication::query()->sole();
    $jobs = queuedJobs();

    // band.give_nickname is the root message, so its correlation_id is its own message_id, which the event names as its cause.
    expect($publication->completed_at)->toBeNull()
        ->and($publication->listener)->toBe(HeadlineOnNicknameGiven::class.'@__invoke')
        ->and($publication->correlation_id)->not->toBeNull()
        ->and($publication->correlation_id)->toBe($publication->causation_id)
        ->and($publication->dispatched_at)->not->toBeNull()
        ->and($publication->actor_id)->toBe((string) $user->id)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->publicationId)->toBe($publication->id);

    runWorker('press');

    $headline = Headline::query()->sole();

    expect($publication->refresh()->completed_at)->not->toBeNull()
        ->and($headline->publication_id)->toBe($publication->id)
        ->and($headline->nickname_id)->toBe($nicknameId)
        ->and($headline->correlation_id)->toBe($publication->correlation_id)
        ->and($headline->printed_by)->toBe($user->id)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('idempotent redelivery: the same publication delivered twice prints once and completes', function (): void {
    $this->actingAs(User::factory()->member()->create());
    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $publication = EventPublication::query()->sole();

    app(Dispatcher::class)->dispatch((new ExecuteHandlerJob($publication->id))->onQueue('press'));
    expect(DB::table('jobs')->count())->toBe(2);

    runWorker('press');

    expect(Headline::query()->count())->toBe(1)
        ->and(Headline::query()->sole()->publication_id)->toBe($publication->id)
        ->and($publication->refresh()->completed_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('A11: an actor deleted after the push fails the job on its first attempt with ModelNotFoundException and leaves the row incomplete', function (): void {
    config(['logging.default' => 'null', 'laravents.async.tries' => 3]);
    $user = User::factory()->member()->create();
    $this->actingAs($user);
    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $attempts = countAttempts();

    $user->delete();
    runWorker('press');

    $exception = DB::table('failed_jobs')->sole()->exception;
    $this->assertIsString($exception);

    expect($exception)->toContain(ModelNotFoundException::class)
        ->toContain('No query results for model [Workbench\App\Models\User] '.$user->id)
        ->and(EventPublication::query()->sole()->completed_at)->toBeNull()
        ->and($attempts())->toBe(1)
        ->and(Headline::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});
