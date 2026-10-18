<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

// Error bodies as production renders them: without the debug trace.
beforeEach(function (): void {
    config(['app.debug' => false]);
});

test('POST /laravents/commands/band.give_nickname gives the nickname as the authenticated member and answers {"data": id}', function (): void {
    $user = User::factory()->member()->create();

    $response = $this->actingAs($user)->postJson('/laravents/commands/band.give_nickname', ['memberId' => $user->id + 1, 'nickname' => 'The Quiet One']);

    $nickname = Nickname::query()->sole();

    $response->assertOk()->assertExactJson(['data' => $nickname->id]);
    expect($nickname->given_by)->toBe($user->id)
        ->and($nickname->member_id)->toBe($user->id + 1);
});

test('rules() failing is a 422 in Laravel\'s error shape, and the handler never runs', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->postJson('/laravents/commands/band.give_nickname', ['memberId' => 0, 'nickname' => 'The Quiet One'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The member id field must be at least 1.',
            'errors' => ['memberId' => ['The member id field must be at least 1.']],
        ]);

    // A command reads the body only: the query string is not its payload.
    $this->postJson('/laravents/commands/band.give_nickname?memberId=2')
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The member id field is required. (and 1 more error)',
            'errors' => [
                'memberId' => ['The member id field is required.'],
                'nickname' => ['The nickname field is required.'],
            ],
        ]);

    expect(Nickname::query()->count())->toBe(0);
});

test('a payload that cannot hydrate the message is a 422 too', function (): void {
    $this->getJson('/laravents/queries/band.get_nickname')
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The id field is required.',
            'errors' => ['id' => ['The id field is required.']],
        ]);

    $this->getJson('/laravents/queries/band.get_nickname?id=abc')
        ->assertUnprocessable()
        ->assertJsonPath('errors.id', ['The id field must be of type int.']);
});

test('#[Authorize] refusing is a 403', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)->postJson('/laravents/commands/band.give_nickname', ['memberId' => $user->id, 'nickname' => 'The Quiet One'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'This action is unauthorized.']);

    expect(Nickname::query()->count())->toBe(0);
});

test('a Gate answering denyAsNotFound() is a 404 through Laravel\'s handler, and the handler never runs', function (): void {
    Gate::define('band.give_nickname', static fn (): Response => Response::denyAsNotFound());

    $this->actingAs(User::factory()->member()->create())->postJson('/laravents/commands/band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One'])
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not Found']);

    expect(Nickname::query()->count())->toBe(0);
});

test('a guest is a 401', function (): void {
    $this->postJson('/laravents/commands/band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a query is asked by GET with the query string and by POST with the body, its DTO answered as {"data": {...}}', function (): void {
    Date::setTestNow('2026-10-15 10:00:00');
    $user = User::factory()->member()->create();
    $nickname = Nickname::query()->create(['given_by' => $user->id, 'member_id' => 7, 'nickname' => 'The Quiet One']);
    $expected = ['data' => [
        'id' => $nickname->id,
        'givenBy' => $user->id,
        'memberId' => 7,
        'nickname' => 'The Quiet One',
        'givenAt' => '2026-10-15T10:00:00.000000Z',
    ]];

    $this->getJson('/laravents/queries/band.get_nickname?id='.$nickname->id)->assertOk()->assertExactJson($expected);
    $this->postJson('/laravents/queries/band.get_nickname', ['id' => $nickname->id])->assertOk()->assertExactJson($expected);
});

test('an unexposed, an unknown and a wrong-kind key are the same 404, with nothing about the key in it', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $unexposed = $this->postJson('/laravents/commands/press.print_headline', ['nicknameId' => 1, 'memberId' => 2, 'publicationId' => 'p'])->assertNotFound();
    $unknown = $this->postJson('/laravents/commands/band.give_nickame', ['memberId' => 2, 'nickname' => 'The Quiet One'])->assertNotFound();
    $wrongKind = $this->getJson('/laravents/queries/band.give_nickname?memberId=2')->assertNotFound();
    $commandForQuery = $this->postJson('/laravents/commands/band.get_nickname', ['id' => 1])->assertNotFound();

    expect($unexposed->getContent())
        ->toBe($unknown->getContent())
        ->toBe($wrongKind->getContent())
        ->toBe($commandForQuery->getContent())
        ->not->toContain('Did you mean')
        ->not->toContain('band.')
        ->not->toContain('press')
        ->and(json_decode((string) $unknown->getContent(), true))->toBe(['message' => ''])
        ->and(Nickname::query()->count())->toBe(0);
});
