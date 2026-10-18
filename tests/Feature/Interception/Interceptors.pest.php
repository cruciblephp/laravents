<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Laravents\Discovery\AppliedInterceptor;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Interception\Lab\Api\Happened;
use Laravents\Tests\Fixtures\Interception\Lab\Attributes\Limited;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\AuditedHandlers;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\DeadlockHandlers;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\HappenedListeners;
use Laravents\Tests\Fixtures\Interception\Lab\Handlers\LabHandlers;
use Laravents\Tests\Fixtures\Interception\Lab\Interceptors\LabInterceptors;
use Laravents\Tests\Fixtures\Recorder;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Recorder::reset();

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Interception'))->build([
        LabHandlers::class,
        AuditedHandlers::class,
        DeadlockHandlers::class,
        HappenedListeners::class,
        LabInterceptors::class,
    ]));
});

test('three interceptors run by precedence, lower first and outermost, whatever their declaration order', function (): void {
    $result = Laravents::command('lab.run');

    expect($result)->toBe('ran')
        ->and(Recorder::handlers())->toBe(['before', 'around in', 'handler', 'after', 'around out'])
        ->and(Recorder::calls()[0][1])->toBe('lab.run')
        ->and(Recorder::calls()[1][1])->toBe('run')
        ->and(Recorder::calls()[3][1])->toBe('ran');
});

test('a handler no pointcut matches runs without interceptors', function (): void {
    expect(Laravents::command('lab.plain'))->toBe('plain')
        ->and(Recorder::handlers())->toBe(['plain']);
});

test('an Around receives the matched attribute instance and headers, and may change the result', function (): void {
    expect(Laravents::query('lab.look', [], ['correlation_id' => 'c-1']))->toBe(11)
        ->and(Recorder::calls())->toBe([['limit', [3, 'c-1']]]);
});

test('an Around that does not proceed short-circuits the handler', function (): void {
    expect(Laravents::command('lab.skip'))->toBe('from interceptor')
        ->and(Recorder::handlers())->toBe([]);
});

test('a pointcut by interface matches handler classes implementing it, and a Before can take the payload by type', function (): void {
    expect(Laravents::command('lab.audit_me', ['who' => 'ada']))->toBe('audited')
        ->and(Recorder::calls())->toBe([['audited', 'ada'], ['audit handler', null]]);
});

test('a pointcut attribute on the handler class applies to each of its event handlers, around each one', function (): void {
    Laravents::publish(new Happened);

    expect(Recorder::handlers())->toBe([
        'before', 'around in', 'first listener', 'after', 'around out',
        'before', 'around in', 'second listener', 'after', 'around out',
    ]);
});

test('the registry lists a handler\'s interceptors in precedence order', function (): void {
    $registry = app(Registry::class);
    $handler = $registry->commandHandler('lab.run');
    $this->assertNotNull($handler);

    expect(array_map(fn (AppliedInterceptor $applied): string => $applied->interceptor->kind->value.' '.$applied->interceptor->method, $registry->interceptorsFor($handler)))
        ->toBe(['before before', 'around around', 'after after']);
});

test('the registry keeps the pointcut attribute instance it matched by, so it is not looked up per call', function (): void {
    $registry = app(Registry::class);
    $handler = $registry->commandHandler('lab.audit_me');
    $look = $registry->queryHandler('lab.look');
    $this->assertNotNull($handler);
    $this->assertNotNull($look);

    $applied = $registry->interceptorsFor($look)[0];
    $this->assertInstanceOf(Limited::class, $applied->attribute);

    expect($applied->attribute->max)->toBe(3)
        ->and($registry->interceptorsFor($handler)[0]->attribute)->toBeNull();
});

test('Laravel contextual attributes resolve in an interceptor, and #[Context] sees the same correlation_id as the header', function (): void {
    $user = User::factory()->member()->make(['id' => 7]);
    $this->actingAs($user);

    expect(Laravents::withHeader('correlation_id', 'req-5')->query('lab.peek'))->toBe('peeked')
        ->and(Recorder::calls())->toBe([['contextual', [7, 'req-5', 'req-5']]]);
});

test('#[Transactional(attempts: 2)] retries a deadlock as DB::transaction() does', function (): void {
    expect(Laravents::command('lab.retry_on_deadlock'))->toBe(2)
        ->and(Recorder::handlers())->toBe(['attempt', 'attempt']);
});

test('#[Transactional] with the default single attempt lets the deadlock through', function (): void {
    expect(fn () => Laravents::command('lab.fail_on_deadlock'))->toThrow(QueryException::class, 'Deadlock found')
        ->and(Recorder::handlers())->toBe(['attempt']);
});
