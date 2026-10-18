<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;
use Laravents\LaraventsServiceProvider;
use Laravents\Outbox\EventPublication;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

afterEach(function (): void {
    app(RegistryCache::class)->clear();
});

/** The registry a boot would build with the current config, put in place of the booted one. */
function rescannedRegistry(): Registry
{
    $registry = LaraventsServiceProvider::scan(config());
    app()->instance(Registry::class, $registry);

    return $registry;
}

test('a disabled module is left out of discovery, and the other modules stay', function (): void {
    config(['laravents.modules.disabled' => ['Press']]);
    $registry = rescannedRegistry();

    expect($registry->commandHandler('band.give_nickname'))->not->toBeNull()
        ->and($registry->eventHandlersFor(NicknameGiven::class))->toBe([])
        ->and(array_values(array_unique(array_map(static fn (HandlerDefinition $handler): ?string => $handler->module, $registry->all()))))->toBe(['Band', 'FanClub']);
});

test('the disabled module\'s listener gets nothing: band.give_nickname commits its nickname and writes no outbox row', function (): void {
    config(['laravents.modules.disabled' => ['Press']]);
    rescannedRegistry();
    $this->actingAs(User::factory()->member()->create());

    $id = GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    expect(Nickname::query()->find($id))->not->toBeNull()
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(queuedJobs())->toBe([]);
});

test('with nothing disabled, the same send writes the Press module\'s row', function (): void {
    rescannedRegistry();
    $this->actingAs(User::factory()->member()->create());

    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');

    expect(EventPublication::query()->count())->toBe(1);
});

test('laravents:cache writes the registry without the disabled module', function (): void {
    config(['laravents.modules.disabled' => ['Press']]);

    expect(Artisan::call('laravents:cache'))->toBe(0)
        ->and(app(RegistryCache::class)->load()->eventHandlersFor(NicknameGiven::class))->toBe([]);
});

test('laravents:verify still checks every module, the disabled one too', function (): void {
    config(['laravents.modules.disabled' => ['Press']]);

    expect(Artisan::call('laravents:verify'))->toBe(0)
        ->and(Artisan::output())->toContain('Press -> Band')->toContain('3 modules verified');
});

test('a name that is not a module disables nothing', function (): void {
    config(['laravents.modules.disabled' => ['Presses']]);

    expect(rescannedRegistry()->eventHandlersFor(NicknameGiven::class))->toHaveCount(1);
});
