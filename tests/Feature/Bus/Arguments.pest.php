<?php

declare(strict_types=1);

use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Invocation\Arguments;
use Laravents\Invocation\ParameterPlan;
use Laravents\Tests\Fixtures\Invocation\Probe\Handlers\ProbeHandlers;

test('a handler\'s parameters are reflected on its first dispatch only: the second dispatch reuses the same plan', function (): void {
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Invocation'))->build([ProbeHandlers::class]));
    $key = ProbeHandlers::class.'::ping';
    // The cache itself, read without building anything, so the test sees what the dispatches did.
    $plans = static fn (): mixed => (new ReflectionProperty(Arguments::class, 'plans'))->getValue();

    expect($plans())->toBeArray()->not->toHaveKey($key);

    expect(Laravents::command('probe.ping', ['n' => 1]))->toBe(1)
        ->and($plans())->toHaveKey($key);

    // Built by the dispatch above, so plan() hands back the cached list rather than building one.
    $first = Arguments::plan(ProbeHandlers::class, 'ping');

    expect($plans()[$key])->toBe($first)
        ->and($first)->toHaveCount(2)
        ->and(array_map(static fn (ParameterPlan $parameter): array => [$parameter->name, $parameter->header], $first))
        ->toBe([['payload', null], ['correlationId', 'correlation_id']]);

    expect(Laravents::command('probe.ping', ['n' => 2]))->toBe(2)
        // The same ParameterPlan objects (toBe is ===), so the second dispatch built nothing.
        ->and($plans()[$key])->toBe($first);
});
