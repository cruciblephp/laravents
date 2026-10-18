<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Bus\ContextMetadata;
use Modules\Band\Api\GiveNickname;
use Modules\Press\Models\Headline;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

test('A1: band.give_nickname queues the headline after commit, and the worker prints it as the member who gave the nickname', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $nicknameId = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    $jobs = queuedJobs();
    expect($jobs)->toHaveCount(1)
        ->and(Headline::query()->count())->toBe(0);

    // band.give_nickname is the root message, so its correlation_id is its own message_id, which the event names as its cause.
    $correlationId = publicationOf($jobs[0])->correlation_id;
    $this->assertIsString($correlationId);
    expect(publicationOf($jobs[0])->causation_id)->toBe($correlationId);

    runWorker('press');

    $headline = Headline::query()->sole();

    expect($headline->member_id)->toBe(2)
        ->and($headline->nickname_id)->toBe($nicknameId)
        ->and($headline->correlation_id)->toBe($correlationId)
        ->and($headline->printed_by)->toBe($user->id)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('A8: one worker run delivers two members\' jobs, each with its own correlation id and actor, and leaves no metadata or user behind', function (): void {
    $john = User::factory()->member()->create(['name' => 'John']);
    $paul = User::factory()->member()->create(['name' => 'Paul']);

    $this->actingAs($john);
    $first = GiveNickname::send(memberId: $paul->id, nickname: 'The Cute One');
    $this->actingAs($paul);
    $second = GiveNickname::send(memberId: $john->id, nickname: 'The Smart One');

    $correlations = array_map(static fn (ExecuteHandlerJob $job): ?string => publicationOf($job)->correlation_id, queuedJobs());
    expect($correlations)->toHaveCount(2)
        ->and($correlations[0])->not->toBe($correlations[1]);

    runWorker('press');

    $rows = Headline::query()->orderBy('id')->get()
        ->map(static fn (Headline $headline): array => [$headline->nickname_id, $headline->member_id, $headline->printed_by, $headline->correlation_id])
        ->all();

    expect($rows)->toBe([
        [$first, $paul->id, $john->id, $correlations[0]],
        [$second, $john->id, $paul->id, $correlations[1]],
    ])
        ->and(array_intersect_key(Context::all(), array_flip(ContextMetadata::KEYS)))->toBe([])
        ->and(auth()->check())->toBeFalse();
});

test('on the sync connection the caller keeps their user: two band.give_nickname calls as one member both print, as that member', function (): void {
    config(['queue.default' => 'sync']);
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $first = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $second = GiveNickname::send(memberId: 3, nickname: 'The Quiet One');

    $rows = Headline::query()->orderBy('id')->get()
        ->map(static fn (Headline $headline): array => [$headline->nickname_id, $headline->member_id, $headline->printed_by])
        ->all();

    expect($rows)->toBe([[$first, 2, $user->id], [$second, 3, $user->id]])
        ->and(auth()->user())->toBe($user);
});
