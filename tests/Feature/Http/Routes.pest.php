<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravents\LaraventsServiceProvider;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

/** Registers the provider again, routes and all, on an empty route table, as a boot with the current config would. */
function rebootLaraventsRoutes(): void
{
    app('router')->setRoutes(new RouteCollection);
    app()->register(LaraventsServiceProvider::class, force: true);
}

test('the routes are named, take GET|POST for queries and POST for commands, under the default prefix', function (): void {
    $commands = Route::getRoutes()->getByName('laravents.commands');
    $queries = Route::getRoutes()->getByName('laravents.queries');

    expect($commands?->uri())->toBe('laravents/commands/{key}')
        ->and($commands?->methods())->toBe(['POST'])
        ->and($commands?->gatherMiddleware())->toBe([])
        ->and($queries?->uri())->toBe('laravents/queries/{key}')
        ->and($queries?->methods())->toBe(['GET', 'POST', 'HEAD']);
});

test('the prefix and middleware come from laravents.http, and the HTTP column follows the prefix', function (): void {
    config(['laravents.http.prefix' => '/api/bus/', 'laravents.http.middleware' => ['api', 'throttle:60,1']]);
    rebootLaraventsRoutes();

    $commands = Route::getRoutes()->getByName('laravents.commands');

    expect($commands?->uri())->toBe('api/bus/commands/{key}')
        ->and($commands?->gatherMiddleware())->toBe(['api', 'throttle:60,1']);

    Artisan::call('laravents:list');

    expect(Artisan::output())->toContain('POST /api/bus/commands/band.give_nickname')->toContain('GET /api/bus/queries/band.get_nickname');
});

test('command_middleware and query_middleware go on their own route only, after the shared middleware', function (): void {
    config([
        'laravents.http.middleware' => ['api'],
        'laravents.http.command_middleware' => ['throttle:10,1'],
        'laravents.http.query_middleware' => ['cache.headers:etag'],
    ]);
    rebootLaraventsRoutes();

    expect(Route::getRoutes()->getByName('laravents.commands')?->gatherMiddleware())->toBe(['api', 'throttle:10,1'])
        ->and(Route::getRoutes()->getByName('laravents.queries')?->gatherMiddleware())->toBe(['api', 'cache.headers:etag']);
});

test('a query route with cache.headers answers 304 to a matching If-None-Match, and the command route is untouched', function (): void {
    config(['laravents.http.query_middleware' => ['cache.headers:etag']]);
    rebootLaraventsRoutes();
    $this->actingAs(User::factory()->member()->create());
    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $nicknameId = Nickname::query()->sole()->id;

    $first = $this->getJson('/laravents/queries/band.get_nickname?id='.$nicknameId)->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->toBeString();

    $this->getJson('/laravents/queries/band.get_nickname?id='.$nicknameId, ['If-None-Match' => (string) $etag])->assertStatus(304);
    expect(Route::getRoutes()->getByName('laravents.commands')?->gatherMiddleware())->toBe([]);
});

test('with laravents.http.enabled false there are no routes, and the HTTP column is empty', function (): void {
    config(['laravents.http.enabled' => false]);
    rebootLaraventsRoutes();

    expect(Route::has('laravents.commands'))->toBeFalse()
        ->and(Route::has('laravents.queries'))->toBeFalse();

    $this->postJson('/laravents/commands/band.give_nickname', ['memberId' => 2, 'nickname' => 'The Quiet One'])->assertNotFound();

    Artisan::call('laravents:list');

    expect(Artisan::output())->not->toContain('/laravents/');
});
