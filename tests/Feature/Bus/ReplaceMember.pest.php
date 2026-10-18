<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Modules\Band\Api\Instrument;
use Modules\Band\Api\MemberReplaced;
use Modules\Band\Api\ReplaceMember;
use Modules\Band\Models\Lineup;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** @return array{User, User, User} John, Pete and Ringo, as in August 1962: Pete on drums, Ringo not yet in the band. */
function lineup1962(): array
{
    return [
        User::factory()->member(Instrument::Guitar)->create(['name' => 'John']),
        User::factory()->member(Instrument::Drums)->create(['name' => 'Pete']),
        User::factory()->create(['name' => 'Ringo']),
    ];
}

test('a member replaces Pete with Ringo: Ringo joins on drums, Pete leaves, and the change is recorded', function (): void {
    [$john, $pete, $ringo] = lineup1962();
    $this->actingAs($john);

    ReplaceMember::send(outId: $pete->id, inId: $ringo->id);

    $change = Lineup::query()->sole();
    expect($change->out_id)->toBe($pete->id)
        ->and($change->in_id)->toBe($ringo->id)
        ->and($change->instrument)->toBe(Instrument::Drums)
        ->and($pete->refresh()->is_member)->toBeFalse()
        ->and($pete->instrument)->toBeNull()
        ->and($ringo->refresh()->is_member)->toBeTrue()
        ->and($ringo->instrument)->toBe(Instrument::Drums);
});

test('the change publishes MemberReplaced, carrying the instrument from the Band\'s Api', function (): void {
    [$john, $pete, $ringo] = lineup1962();
    $this->actingAs($john);
    $fake = Laravents::fake([MemberReplaced::class]);

    ReplaceMember::send(outId: $pete->id, inId: $ringo->id);

    $fake->assertPublishedTimes(MemberReplaced::class, 1);
    $fake->assertPublished(MemberReplaced::class, static fn (MemberReplaced $event): bool => $event->outId === $pete->id
        && $event->inId === $ringo->id
        && $event->instrument === Instrument::Drums);
});

test('FanClub archives MemberReplaced through its band.* pattern, with no code of its own for the new event', function (): void {
    [$john, $pete, $ringo] = lineup1962();
    $this->actingAs($john);
    $log = Log::spy();

    ReplaceMember::send(outId: $pete->id, inId: $ringo->id);

    $log->shouldHaveReceived('info')->withArgs(static fn (string $message, array $context): bool => $message === 'Band event'
        && $context['event'] === MemberReplaced::class)->once();
});

test('a lineup change that fails after Pete was updated is rolled back whole: Pete stays, Ringo stays out', function (): void {
    [$john, $pete, $ringo] = lineup1962();
    $this->actingAs($john);
    // The change itself cannot be recorded, so the command fails after both members were already written.
    Schema::drop('lineup_changes');

    expect(fn (): mixed => ReplaceMember::send(outId: $pete->id, inId: $ringo->id))->toThrow(QueryException::class)
        ->and($pete->refresh()->is_member)->toBeTrue()
        ->and($pete->instrument)->toBe(Instrument::Drums)
        ->and($ringo->refresh()->is_member)->toBeFalse()
        ->and(EventPublication::query()->count())->toBe(0);
});

test('an unknown member is refused before anything is written', function (): void {
    [$john, $pete] = lineup1962();
    $this->actingAs($john);

    expect(fn (): mixed => ReplaceMember::send(outId: $pete->id, inId: 999))->toThrow(ModelNotFoundException::class)
        ->and($pete->refresh()->is_member)->toBeTrue()
        ->and(Lineup::query()->count())->toBe(0);
});

test('nobody replaces themselves, and a fan replaces nobody', function (): void {
    [, $pete, $ringo] = lineup1962();

    $this->actingAs($pete);
    expect(fn (): mixed => ReplaceMember::send(outId: $pete->id, inId: $ringo->id))->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create(['name' => 'Luciano']));
    expect(fn (): mixed => ReplaceMember::send(outId: $pete->id, inId: $ringo->id))->toThrow(AuthorizationException::class)
        ->and(Lineup::query()->count())->toBe(0);
});

test('rules() refuses a member replaced by themselves, or a member id below 1, at the edge where rules() runs', function (array $fields, array $errors): void {
    [, $pete] = lineup1962();
    $fields = array_map(static fn (mixed $value): mixed => $value === 'pete' ? $pete->id : $value, $fields);

    $e = caught(ValidationException::class, static fn (): ReplaceMember => ReplaceMember::fromRequest(Request::create('/lineup', 'POST', $fields)));

    expect($e->errors())->toBe($errors);
})->with([
    'the same member out and in' => [['outId' => 'pete', 'inId' => 'pete'], ['inId' => ['The in id field and out id must be different.']]],
    'an out id below 1' => [['outId' => 0, 'inId' => 'pete'], ['outId' => ['The out id field must be at least 1.']]],
]);
