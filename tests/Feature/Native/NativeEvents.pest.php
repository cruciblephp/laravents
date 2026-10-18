<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravents\Bus\NativeEvents;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Native\Audit\Api\Shipped;
use Laravents\Tests\Fixtures\Native\Audit\Handlers\AuditListeners;
use Laravents\Tests\Fixtures\Native\Settlement\Handlers\SettlementListeners;
use Laravents\Tests\Fixtures\NativeApp\Events\InvoiceSettled;
use Laravents\Tests\Fixtures\NativeApp\Events\OrderShipped;
use Laravents\Tests\Fixtures\NativeApp\Events\Settles;
use Laravents\Tests\Fixtures\Recorder;
use Modules\Band\Api\NicknameGiven;
use Modules\FanClub\Models\Welcome;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
});

/** The Audit fixture's registry in place of the app's, subscribed to Laravel's dispatcher as boot subscribes the app's. */
function subscribeAudit(): Registry
{
    $registry = (new RegistryBuilder('Laravents\Tests\Fixtures\Native'))->build([AuditListeners::class]);
    app()->instance(Registry::class, $registry);
    app(NativeEvents::class)->subscribe($registry, app('events'));

    return $registry;
}

test('the registry lists the framework and application event classes, not module events or keyed handlers', function (): void {
    expect(app(Registry::class)->externalEventClasses())->toBe([Registered::class])
        ->and(subscribeAudit()->externalEventClasses())->toBe([Login::class, OrderShipped::class]);
});

test('event(new Registered($user)) queues the welcome as the acting user, and the worker sends it through #[Authorize]', function (): void {
    $user = User::factory()->create(['name' => 'Luciano']);
    $this->actingAs($user);

    event(new Registered($user));

    $jobs = queuedJobs();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->queue)->toBe('fan_club');

    $publication = publicationOf($jobs[0]);

    // A root message: a fresh correlation id, its own message id.
    expect($publication->listener)->toBe('Modules\FanClub\Handlers\WelcomeOnRegistered@__invoke')
        ->and($publication->event_class)->toBe(Registered::class)
        ->and(Str::isUlid((string) $publication->message_id))->toBeTrue()
        ->and($publication->correlation_id)->toBe($publication->message_id)
        ->and($publication->causation_id)->toBeNull()
        ->and($publication->actor_id)->toBe((string) $user->id);

    runWorker('fan_club');

    $welcome = Welcome::query()->sole();

    expect($welcome->user_id)->toBe($user->id)
        ->and($welcome->sent_by)->toBe($user->id)
        ->and($welcome->correlation_id)->toBe($publication->correlation_id)
        ->and($welcome->publication_id)->toBe($publication->id)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('a correlation id already in Context is joined', function (): void {
    $user = User::factory()->create(['name' => 'Luciano']);
    $this->actingAs($user);
    Context::add('correlation_id', 'request-1');

    event(new Registered($user));

    expect(EventPublication::query()->sole()->correlation_id)->toBe('request-1');
});

test('a guest registration has no actor on its row; the handler says who it acts for (tests/Feature/Auth)', function (): void {
    event(new Registered(User::factory()->create(['name' => 'Luciano'])));

    expect(EventPublication::query()->sole()->actor_id)->toBeNull();
});

test('an event whose class and interface both have handlers is published once, so each handler runs once', function (): void {
    $registry = (new RegistryBuilder('Laravents\Tests\Fixtures\Native'))->build([SettlementListeners::class]);
    app()->instance(Registry::class, $registry);
    app(NativeEvents::class)->subscribe($registry, app('events'));

    expect($registry->externalEventClasses())->toBe([InvoiceSettled::class, Settles::class]);

    InvoiceSettled::dispatch(5);

    [[, $byClass], [, $byInterface]] = Recorder::calls();
    expect(Recorder::handlers())->toBe(['class', 'interface'])
        ->and($byInterface)->toBe($byClass);

    // A second dispatch is a second event object, so it is published again.
    InvoiceSettled::dispatch(6);
    expect(Recorder::handlers())->toBe(['class', 'interface', 'class', 'interface']);
});

test('Event::fake() keeps the event from Laravents, as from any listener', function (): void {
    Event::fake();
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    event(new Registered($user));

    Event::assertDispatched(Registered::class);
    expect(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('Laravents::fake() records the framework event as published', function (): void {
    Laravents::fake();
    $user = User::factory()->member()->create();

    event(new Registered($user));

    Laravents::assertPublished(Registered::class, static fn (Registered $event): bool => $event->user === $user);
    expect(EventPublication::query()->count())->toBe(0);
});

test('three handlers of one framework event see one message, published once', function (): void {
    subscribeAudit();
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    event(new Login('web', $user, false));

    expect(Recorder::handlers())->toBe(['first', 'second', 'third']);

    $seen = array_map(static fn (array $call): mixed => $call[1], Recorder::calls());
    expect(array_unique(array_map(serialize(...), $seen)))->toHaveCount(1);

    [$messageId, $correlationId, $actorId] = is_array($seen[0]) ? $seen[0] : [null, null, null];
    expect(is_string($messageId) && Str::isUlid($messageId))->toBeTrue()
        ->and($correlationId)->toBe($messageId)
        ->and($actorId)->toBe((string) $user->id);
});

test('an application event dispatched through Laravel reaches its handler', function (): void {
    subscribeAudit();

    OrderShipped::dispatch(7);

    expect(Recorder::calls())->toBe([['order shipped', 7]]);
});

test('a module event dispatched through event() does nothing: Laravents::publish() is the way', function (): void {
    subscribeAudit();
    $this->actingAs(User::factory()->member()->create());

    event(new Shipped(1));
    event(new NicknameGiven(1, 1, 2, 'The Quiet One'));

    expect(Recorder::calls())->toBe([])
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    Laravents::publish(new Shipped(1));
    expect(Recorder::calls())->toBe([['module shipped', 1]]);
});

test('laravents:list shows the handler with the framework class as its message', function (): void {
    Artisan::call('laravents:list');

    $row = array_values(array_filter(explode("\n", Artisan::output()), static fn (string $line): bool => str_contains($line, 'WelcomeOnRegistered')));

    expect(array_map(trim(...), explode('|', trim($row[0] ?? '', " |\n"))))->toBe([
        'Event', '-', Registered::class, 'Modules\FanClub\Handlers\WelcomeOnRegistered@__invoke', 'async', 'fan_club', '-',
    ]);
});

test('laravents:show FanClub lists Registered under Events consumed, from Laravel', function (): void {
    Artisan::call('laravents:show', ['module' => 'FanClub']);

    expect(preg_replace('/ \.+ | {2,}/', ' ', Artisan::output()))
        ->toContain('Illuminate\Auth\Events\Registered Modules\FanClub\Handlers\WelcomeOnRegistered@__invoke / Laravel / fan_club');
});
