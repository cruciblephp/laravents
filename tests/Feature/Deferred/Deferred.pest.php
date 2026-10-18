<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravents\Discovery\HandlerMode;
use Laravents\Discovery\Registry;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Deferred\Mail\Api\SignedUp;
use Laravents\Tests\Fixtures\Recorder;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();

    config([
        'laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Deferred'],
        'laravents.modules.namespace' => 'Laravents\Tests\Fixtures\Deferred',
    ]);
    app()->forgetInstance(Registry::class);

    // Throwaway routes: publish, then say so; or publish and fail.
    Route::get('/deferred/publish', static function (): string {
        Laravents::publish(new SignedUp('ada@example.com', failLater: request()->boolean('fail')));
        Recorder::record('responded');

        return 'ok';
    });
    Route::get('/deferred/publish-then-fail', static function (): never {
        Laravents::publish(new SignedUp('ada@example.com'));

        throw new RuntimeException('After publishing.');
    });
    Route::get('/deferred/rollback', static function (): string {
        Laravents::command('mail.sign_up', ['email' => 'ada@example.com', 'rollback' => true]);

        return 'unreachable';
    });
});

/**
 * What each handler recorded, by handler name.
 *
 * @return array<string, mixed>
 */
function deferredRecords(): array
{
    $byHandler = [];

    foreach (Recorder::calls() as [$handler, $payload]) {
        $byHandler[$handler] = $payload;
    }

    return $byHandler;
}

test('a deferred handler runs after the response, with the event as published', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)->get('/deferred/publish')->assertOk();

    expect(Recorder::handlers())->toBe(['now', 'responded', 'stamp', 'later', 'welcomed', 'alsoLater']);

    $records = deferredRecords();
    $messageId = $records['now'];

    // The handler saw the event's ids; what it published names the event as its cause; the guard still has the request's user.
    expect($records['later'])->toBe(['message_id' => $messageId, 'correlation_id' => $messageId, 'user' => $user->id])
        ->and($records['welcomed'])->toBe($messageId)
        ->and($records['stamp'])->toBe(SignedUp::class);
});

test('after a guest request the deferred handler finds nobody on the guard', function (): void {
    $this->get('/deferred/publish')->assertOk();

    $records = deferredRecords();
    $later = $records['later'];

    expect(is_array($later) ? $later['user'] : 'missing')->toBeNull();
});

test('a 500 from the route skips the deferred handlers, as Laravel skips deferred callbacks on a 4xx or 5xx', function (): void {
    $this->get('/deferred/publish-then-fail')->assertInternalServerError();

    expect(Recorder::handlers())->toBe(['now']);
});

test('a #[Transactional] publisher that rolls back ends in a 5xx and the deferred handler never runs', function (): void {
    $this->get('/deferred/rollback')->assertInternalServerError();

    expect(Recorder::handlers())->toBe(['now']);
});

test('a rolled-back publisher whose exception is caught still drops the deferred handler', function (): void {
    Artisan::command('deferred:swallow', function (): int {
        try {
            Laravents::command('mail.sign_up', ['email' => 'ada@example.com', 'rollback' => true]);
        } catch (RuntimeException) {
            Recorder::record('caught');
        }

        return 0;
    });

    expect(Artisan::call('deferred:swallow'))->toBe(0)
        ->and(Recorder::handlers())->toBe(['now', 'caught']);
});

test('a committed #[Transactional] publisher defers the handler until the response', function (): void {
    Route::get('/deferred/sign-up', static function (): string {
        Laravents::command('mail.sign_up', ['email' => 'ada@example.com']);
        Recorder::record('responded');

        return 'ok';
    });

    $this->get('/deferred/sign-up')->assertOk();

    expect(Recorder::handlers())->toBe(['now', 'responded', 'stamp', 'later', 'welcomed', 'alsoLater']);
});

test('an Artisan command that publishes runs the deferred handler at the end of the command', function (): void {
    // Laravel turns Symfony's terminate event into CommandFinished, which runs the deferred callbacks, only outside
    // unit tests (APP_ENV=testing); a real `php artisan` run always has it. The Artisan application already built
    // without that dispatcher is dropped, so the next call builds one with it.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);

    Artisan::command('deferred:publish', function (): int {
        Laravents::publish(new SignedUp('ada@example.com'));
        Recorder::record('command done');

        return 0;
    });

    expect(Artisan::call('deferred:publish'))->toBe(0)
        ->and(Recorder::handlers())->toBe(['now', 'command done', 'stamp', 'later', 'welcomed', 'alsoLater']);
});

test('a deferred handler that throws is reported, the response stands and the next deferred handler runs', function (): void {
    Exceptions::fake();

    $this->get('/deferred/publish?fail=1')->assertOk()->assertContent('ok');

    Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'The welcome mail could not be sent.');

    expect(Recorder::handlers())->toBe(['now', 'responded', 'stamp', 'later', 'alsoLater']);
});

test('a faked event reaches no deferred handler', function (): void {
    Laravents::fake([SignedUp::class]);

    $this->get('/deferred/publish')->assertOk();

    Laravents::assertPublished(SignedUp::class);
    expect(Recorder::handlers())->toBe(['responded']);
});

test('the registry marks the deferred handlers, with no queue', function (): void {
    $modes = [];

    foreach (app(Registry::class)->eventHandlersFor(SignedUp::class) as $handler) {
        $modes[$handler->method] = [$handler->mode, $handler->queue, $handler->isDeferred(), $handler->isAsynchronous()];
    }

    expect($modes)->toBe([
        'now' => [HandlerMode::Sync, null, false, false],
        'later' => [HandlerMode::Deferred, null, true, false],
        'alsoLater' => [HandlerMode::Deferred, null, true, false],
    ]);
});

test('laravents:list and laravents:show print deferred in the Mode column', function (): void {
    Artisan::call('laravents:list');
    $rows = array_map(
        static fn (string $line): array => array_map(trim(...), explode('|', trim($line, " |\n"))),
        array_values(array_filter(explode("\n", Artisan::output()), static fn (string $line): bool => str_contains($line, 'SignedUpListeners'))),
    );

    expect(array_map(static fn (array $row): array => [$row[3], $row[4], $row[5]], $rows))->toBe([
        ['Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@now', 'sync', '-'],
        ['Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@later', 'deferred', '-'],
        ['Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@alsoLater', 'deferred', '-'],
        ['Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@welcomed', 'sync', '-'],
    ]);

    Artisan::call('laravents:show', ['module' => 'Mail']);
    expect(preg_replace('/ \.+ | {2,}/', ' ', Artisan::output()))
        ->toContain('Laravents\Tests\Fixtures\Deferred\Mail\Api\SignedUp Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@later / Mail / deferred')
        ->toContain('Laravents\Tests\Fixtures\Deferred\Mail\Api\SignedUp Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@now / Mail / sync');

    Artisan::call('laravents:show', ['module' => 'Mail', '--json' => true]);
    $json = json_decode(Artisan::output(), true);
    $published = is_array($json) && is_array($json['events_published'] ?? null) ? $json['events_published'] : [];
    $signedUp = array_values(array_filter($published, static fn (mixed $event): bool => is_array($event) && $event['event'] === SignedUp::class))[0] ?? [];

    expect($signedUp['listeners'] ?? null)->toContain([
        'handler' => 'Laravents\Tests\Fixtures\Deferred\Mail\Handlers\SignedUpListeners@later',
        'module' => 'Mail',
        'mode' => 'deferred',
        'queue' => null,
    ]);
});
