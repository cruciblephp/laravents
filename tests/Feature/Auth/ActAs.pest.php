<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravents\Bus\Gateway;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Auth\Office\Api\Announce;
use Laravents\Tests\Fixtures\Auth\Office\Api\Greet;
use Laravents\Tests\Fixtures\Auth\Office\Api\Greeted;
use Laravents\Tests\Fixtures\Auth\Office\Api\Stumble;
use Laravents\Tests\Fixtures\Auth\Office\Handlers\GreetedListener;
use Laravents\Tests\Fixtures\Auth\Office\Handlers\OfficeHandlers;
use Laravents\Tests\Fixtures\Recorder;
use Modules\FanClub\Models\Welcome;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** The Office fixture's registry in place of the app's, with its abilities and a second guard. */
function office(): void
{
    Recorder::reset();
    config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Auth'))->build([OfficeHandlers::class, GreetedListener::class]));

    foreach (['office.greet', 'office.stumble', 'office.announce'] as $ability) {
        Gate::define($ability, static fn (Authenticatable $user): bool => true);
    }
}

/** @return array{current: mixed, actor_id: ?string, actor_guard: ?string, admin: mixed, tenant: ?string} */
function greeting(mixed $result): array
{
    expect($result)->toBeArray();

    /** @var array{current: mixed, actor_id: ?string, actor_guard: ?string, admin: mixed, tenant: ?string} $result */
    return $result;
}

function actorInContext(): bool
{
    return Context::has('actor_id') || Context::has('actor_guard');
}

test('a guest process runs an #[Authorize]d command as the given user, and the guard is empty again afterwards', function (): void {
    office();
    $user = User::factory()->member()->create();

    expect(fn () => Laravents::command(new Greet))->toThrow(AuthenticationException::class);

    $greeting = greeting(Laravents::as($user)->command(new Greet));

    expect($greeting['current'])->toBe($user->id)
        ->and($greeting['actor_id'])->toBe((string) $user->id)
        ->and($greeting['actor_guard'])->toBe('web')
        ->and(auth()->guard()->hasUser())->toBeFalse()
        ->and(actorInContext())->toBeFalse();
});

test('with a logged-in user, as($other) runs as $other and the original user is back afterwards', function (): void {
    office();
    $me = User::factory()->member()->create();
    $other = User::factory()->member()->create();
    $this->actingAs($me);

    $greeting = greeting(Laravents::as($other)->command(new Greet));

    expect($greeting['current'])->toBe($other->id)
        ->and($greeting['actor_id'])->toBe((string) $other->id)
        ->and(auth()->user()?->getAuthIdentifier())->toBe($me->id)
        ->and(actorInContext())->toBeFalse()
        ->and(greeting(Laravents::command(new Greet))['current'])->toBe($me->id);
});

test('the guard and Context are put back when the handler throws', function (): void {
    office();
    $me = User::factory()->member()->create();
    $this->actingAs($me);
    Context::add('correlation_id', 'request-1');

    expect(fn () => Laravents::as(User::factory()->member()->create())->command(new Stumble))->toThrow(RuntimeException::class, 'Stumbled.');

    expect(auth()->user()?->getAuthIdentifier())->toBe($me->id)
        ->and(actorInContext())->toBeFalse()
        ->and(Context::get('correlation_id'))->toBe('request-1');
});

test('inside a handler, as() replaces the parent actor for its dispatch only, and keeps the correlation', function (): void {
    office();
    $me = User::factory()->member()->create();
    $other = User::factory()->member()->create();
    $this->actingAs($me);
    Context::add(['message_id' => 'parent', 'correlation_id' => 'trace-1', 'actor_id' => (string) $me->id, 'actor_guard' => 'web']);

    $greeting = greeting(Laravents::as($other)->command(new Greet));

    expect($greeting['actor_id'])->toBe((string) $other->id)
        ->and(Context::get('actor_id'))->toBe((string) $me->id)
        ->and(Context::get('message_id'))->toBe('parent');

    Laravents::as($other)->publish(new Greeted);
    expect(EventPublication::query()->sole())
        ->correlation_id->toBe('trace-1')
        ->causation_id->toBe('parent')
        ->actor_id->toBe((string) $other->id);
});

test('as() with the builder and a routing key, chained with withHeader() either way round', function (): void {
    office();
    $user = User::factory()->member()->create();

    $first = greeting(Laravents::as($user)->withHeader('tenant', 'acme')->command(new Greet));
    $second = greeting(Laravents::withHeader('tenant', 'acme')->as($user)->command('office.greet'));
    $third = greeting(app(Gateway::class)->as($user)->command('office.greet'));

    expect([$first['current'], $first['tenant']])->toBe([$user->id, 'acme'])
        ->and([$second['current'], $second['tenant']])->toBe([$user->id, 'acme'])
        ->and($third['current'])->toBe($user->id)
        ->and(auth()->guard()->hasUser())->toBeFalse();
});

test('an event published inside as() records that actor on its outbox row, and the worker runs the handler as them', function (): void {
    office();
    $user = User::factory()->member()->create();

    $messageId = Laravents::as($user)->command(new Announce);

    $publication = EventPublication::query()->sole();
    expect($publication->actor_id)->toBe((string) $user->id)
        ->and($publication->actor_guard)->toBe('web')
        ->and($publication->causation_id)->toBe($messageId)
        ->and(auth()->guard()->hasUser())->toBeFalse();

    runWorker('office');

    expect(Recorder::calls())->toBe([['greeted', [$user->id, (string) $user->id]]])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(auth()->guard()->hasUser())->toBeFalse();
});

test('as() on a second guard puts the user there and names that guard as the actor', function (): void {
    office();
    $me = User::factory()->member()->create();
    $admin = User::factory()->member()->create();
    $this->actingAs($me);

    $greeting = greeting(Laravents::as($admin, 'admin')->command(new Greet));

    expect($greeting['actor_id'])->toBe((string) $admin->id)
        ->and($greeting['actor_guard'])->toBe('admin')
        ->and($greeting['admin'])->toBe($admin->id)
        ->and($greeting['current'])->toBe($me->id)
        ->and(auth()->guard('admin')->hasUser())->toBeFalse()
        ->and(auth()->user()?->getAuthIdentifier())->toBe($me->id);
});

test('a guest sign-up is welcomed end to end through the worker, sent by the user who registered', function (): void {
    $user = User::factory()->create(['name' => 'Luciano']);

    event(new Registered($user));

    $publication = EventPublication::query()->sole();
    expect($publication->actor_id)->toBeNull();

    runWorker('fan_club');

    $welcome = Welcome::query()->sole();

    expect($welcome->user_id)->toBe($user->id)
        ->and($welcome->sent_by)->toBe($user->id)
        ->and($welcome->correlation_id)->toBe($publication->correlation_id)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(auth()->guard()->hasUser())->toBeFalse();
});
