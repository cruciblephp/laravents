<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravents\Bus\PayloadConverter;
use Laravents\Discovery\ManifestReader;
use Laravents\Discovery\Module;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryCache;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Facades\Laravents;
use Laravents\LaraventsServiceProvider;
use Laravents\Tests\Fixtures\Manifests\Manifest;
use Modules\Band\Handlers\BandHandlers;
use Modules\Press\Handlers\PressHandlers;
use Workbench\App\Models\User;
use Workbench\App\Support\ModuleManifest;

afterEach(function (): void {
    app(RegistryCache::class)->clear();
});

/** The fixtures' reader: each module.json under tests/Fixtures/Manifests, hydrated into their Manifest. */
function fixtureManifests(bool $required = false): ManifestReader
{
    return new ManifestReader(Manifest::class, $required, dirname(__DIR__, 2).'/Fixtures/Manifests', app(PayloadConverter::class));
}

/** The message a fixture module's manifest fails with. */
function manifestRefusal(string $module, bool $required = false): string
{
    try {
        fixtureManifests($required)->read($module);
    } catch (DiscoveryException $e) {
        return $e->getMessage();
    }

    return 'read';
}

test('Laravents::modules() lists every module by name, sorted, each with its namespace and its manifest', function (): void {
    expect(Laravents::modules())->toEqual([
        'Band' => new Module('Band', 'Modules\Band', false, new ModuleManifest('1.0.0', 'The band: its line-up and the nicknames its members give')),
        'FanClub' => new Module('FanClub', 'Modules\FanClub', false, null),
        'Press' => new Module('Press', 'Modules\Press', false, null),
    ]);
});

test('Laravents::module() is one module, or null for a name that is no module', function (): void {
    expect(Laravents::module('Press')?->namespace)->toBe('Modules\Press')
        ->and(Laravents::module('Presses'))->toBeNull();
});

test('Laravents::manifest() reads one typed as the application\'s class, null when there is none', function (): void {
    expect(Laravents::manifest('Band', ModuleManifest::class)?->version)->toBe('1.0.0')
        ->and(Laravents::manifest('Press', ModuleManifest::class))->toBeNull()
        ->and(Laravents::manifest('Presses', ModuleManifest::class))->toBeNull();
});

test('Laravents::manifest() refuses a class that is not the configured one', function (): void {
    expect(fn (): ?object => Laravents::manifest('Band', Manifest::class))
        ->toThrow(LogicException::class, 'The manifests are '.ModuleManifest::class.', not '.Manifest::class.': laravents.modules.manifest names the class.');
});

test('a disabled module is still listed, marked disabled, with its manifest', function (): void {
    config(['laravents.modules.disabled' => ['Band']]);
    app()->instance(Registry::class, LaraventsServiceProvider::scan(config()));

    expect(Laravents::module('Band')?->disabled)->toBeTrue()
        ->and(Laravents::manifest('Band', ModuleManifest::class)?->version)->toBe('1.0.0')
        ->and(Laravents::module('Press')?->disabled)->toBeFalse();
});

test('with no manifest class configured, no module.json is read', function (): void {
    config(['laravents.modules.manifest' => null]);

    expect(LaraventsServiceProvider::scan(config())->module('Band')?->manifest)->toBeNull();
});

test('the cached registry carries the manifests, so a cached boot reads no module.json', function (): void {
    expect(Artisan::call('laravents:cache'))->toBe(0)
        ->and(app(RegistryCache::class)->load()->module('Band')?->manifest)->toEqual(new ModuleManifest('1.0.0', 'The band: its line-up and the nicknames its members give'));
});

test('fake() leaves the modules as they are: they are not messages', function (): void {
    Laravents::fake();

    expect(Laravents::manifest('Band', ModuleManifest::class)?->version)->toBe('1.0.0');
});

test('a module is a namespace under the root: each once, sorted, a class outside the root none', function (): void {
    expect(array_keys(Module::discover([PressHandlers::class, BandHandlers::class, User::class, BandHandlers::class], 'Modules')))->toBe(['Band', 'Press'])
        ->and(Module::discover([BandHandlers::class], '\Modules\\')['Band']->namespace)->toBe('Modules\Band')
        ->and(Module::discover([BandHandlers::class], 'Modules')['Band']->manifest)->toBeNull();
});

test('a module.json is hydrated into the manifest class, its defaults kept', function (): void {
    expect(fixtureManifests()->read('Ready'))->toEqual(new Manifest('2.0.0', 3))
        ->and(manifestRefusal('Blank'))->toContain('The version field is required.');
});

test('a module with no module.json has no manifest, unless one is required', function (): void {
    expect(fixtureManifests()->read('Bare'))->toBeNull()
        ->and(manifestRefusal('Bare', required: true))->toEndWith('Manifests/Bare/module.json is missing, and laravents.modules.manifest_required asks every module for one.');
});

test('a module.json that does not build the class fails discovery, naming the file and why', function (string $module, string $reason): void {
    expect(manifestRefusal($module))->toBe(dirname(__DIR__, 2).'/Fixtures/Manifests/'.$module.'/module.json '.$reason);
})->with([
    'not JSON' => ['Broken', 'is not valid JSON: Syntax error.'],
    'a mistyped field' => ['Untyped', 'does not build '.Manifest::class.': the version field must be of type string.'],
    'a required field missing' => ['Unversioned', 'does not build '.Manifest::class.': The version field is required.'],
    'fields the class does not take' => ['Extra', 'has [colour, size], which '.Manifest::class.' does not take.'],
    'a list' => ['Listed', 'must hold a JSON object.'],
]);

test('each field is held to its declared type as strict_types would hold it, before the class is built', function (array $fields, ?string $refused): void {
    $path = sys_get_temp_dir().'/laravents-manifest-'.bin2hex(random_bytes(6));
    mkdir($path.'/Typed', 0755, true);
    file_put_contents($path.'/Typed/module.json', json_encode(['version' => '1.0.0', ...$fields], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $reader = new ManifestReader(Manifest::class, false, $path, app(PayloadConverter::class));

    try {
        $read = $reader->read('Typed');
        $message = null;
    } catch (DiscoveryException $e) {
        $read = null;
        $message = $e->getMessage();
    } finally {
        unlink($path.'/Typed/module.json');
        rmdir($path.'/Typed');
        rmdir($path);
    }

    if ($refused === null) {
        expect($message)->toBeNull()->and($read)->toBeInstanceOf(Manifest::class);
    } else {
        expect($message)->toBe($path.'/Typed/module.json does not build '.Manifest::class.': the '.$refused.' field must be of type '.(new ReflectionParameter([Manifest::class, '__construct'], $refused))->getType().'.');
    }
})->with([
    'an int for a float, as JSON writes 1.0' => [['ratio' => 1], null],
    'a whole float for an int, as JSON Schema reads 1.0' => [['weight' => 1.0], null],
    'a fractional float for an int' => [['weight' => 1.5], 'weight'],
    'a float' => [['ratio' => 0.5], null],
    'a string for a float' => [['ratio' => '0.5'], 'ratio'],
    'a bool' => [['stable' => true], null],
    'a number for a bool' => [['stable' => 1], 'stable'],
    'a list for an array' => [['tags' => ['a', 'b']], null],
    'a string for an array' => [['tags' => 'a'], 'tags'],
    'null for a nullable field' => [['note' => null], null],
    'null for a field that is not nullable' => [['weight' => null], 'weight'],
    'a number for a string' => [['note' => 3], 'note'],
    'either side of a union' => [['id' => 'x'], null],
    'neither side of a union' => [['id' => true], 'id'],
    'anything for mixed' => [['extra' => ['any' => [1, 2]]], null],
]);

test('the reader comes from the config: none without a class, and a name that is no class is refused', function (): void {
    config(['laravents.modules.manifest' => null]);
    expect(ManifestReader::fromConfig(config(), app()))->toBeNull();

    config(['laravents.modules.manifest' => 'App\Nope']);
    expect(fn (): ?ManifestReader => ManifestReader::fromConfig(config(), app()))
        ->toThrow(DiscoveryException::class, 'laravents.modules.manifest must be null or the class each module.json is hydrated into.');
});

test('laravents:verify reports a module.json that does not build the class as a registry violation', function (): void {
    config([
        'laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Manifests'],
        'laravents.modules.namespace' => 'Laravents\Tests\Fixtures\Manifests',
        'laravents.modules.path' => dirname(__DIR__, 2).'/Fixtures/Manifests',
        'laravents.modules.manifest' => Manifest::class,
    ]);

    expect(Artisan::call('laravents:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('Broken/module.json is not valid JSON');
});
