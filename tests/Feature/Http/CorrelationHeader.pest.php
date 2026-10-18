<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Modules\Band\Api\GetNickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

test('X-Correlation-ID on a command becomes its correlation_id, which the event and its outbox row carry', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->postJson('/laravents/commands/band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One'], ['X-Correlation-ID' => 'checkout-7f3a'])->assertOk();

    expect(EventPublication::query()->sole()->correlation_id)->toBe('checkout-7f3a');
});

test('without the header the command starts its own trace', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->postJson('/laravents/commands/band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One'])->assertOk();

    $publication = EventPublication::query()->sole();

    expect($publication->correlation_id)->toBeString()->not->toBe('checkout-7f3a')
        ->and($publication->correlation_id)->toBe($publication->causation_id);
});

test('X-Correlation-ID on a query is passed as the correlation_id header', function (): void {
    $fake = Laravents::fake();

    $this->getJson('/laravents/queries/band.get_nickname?id=1', ['X-Correlation-ID' => 'report-9'])->assertNoContent();

    $fake->assertAsked(GetNickname::class, static fn (GetNickname $query, array $headers): bool => $query->id === 1 && $headers === ['correlation_id' => 'report-9']);
});

test('a header too long for the outbox column is a 422, and the command never runs', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->postJson('/laravents/commands/band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One'], ['X-Correlation-ID' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['correlation_id']);

    expect(EventPublication::query()->count())->toBe(0);
});
