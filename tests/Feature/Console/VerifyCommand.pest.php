<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Tests\Fixtures\Verify\Direction\Billing\Handlers\ChargeOnOrderPlaced;
use Laravents\Tests\Fixtures\Verify\Direction\Orders\Models\Order;

/**
 * Points discovery at one fixture module set under tests/Fixtures/Verify (or any directory and namespace) for this
 * test only. The base path is the package root, so the fixtures sit under it as an application's modules do and the
 * command prints their files relative to it (under Testbench it is otherwise the skeleton in vendor/).
 */
function verifyFixture(string $set, ?string $namespace = null): void
{
    app()->setBasePath(dirname(__DIR__, 3));

    config([
        'laravents.paths' => [str_contains($set, '/') ? dirname(__DIR__, 3).'/'.$set : dirname(__DIR__, 2).'/Fixtures/Verify/'.$set],
        'laravents.modules.namespace' => $namespace ?? 'Laravents\Tests\Fixtures\Verify\\'.$set,
    ]);
}

/**
 * The exit code and the rendered rows, trimmed, with twoColumnDetail()'s dot leader and any run of spaces (both depend
 * on the terminal width) folded into one space, and the blank lines dropped.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: list<string>}
 */
function verifyRows(array $options = []): array
{
    $code = Artisan::call('laravents:verify', $options);
    $rows = array_map(
        static fn (string $line): string => trim((string) preg_replace(['/ \.{2,}( |$)/', '/ {2,}/'], ' ', $line)),
        explode("\n", Artisan::output()),
    );

    return [$code, array_values(array_filter($rows, static fn (string $row): bool => $row !== ''))];
}

test('the real modules pass', function (): void {
    expect(verifyRows())->toBe([0, [
        'Module graph 3 modules',
        'FanClub',
        'Press -> Band',
        'INFO 3 modules verified, no violations.',
    ]]);
});

test('a clean pair passes, whatever its comments and strings say about the internals', function (): void {
    verifyFixture('Clean');

    expect(verifyRows())->toBe([0, [
        'Module graph 2 modules',
        'Billing -> Orders',
        'INFO 2 modules verified, no violations.',
    ]]);
});

test('a reference to another module outside its Api is a direction violation, with file and line', function (): void {
    verifyFixture('Direction');

    expect(verifyRows())->toBe([1, [
        'Module graph 2 modules',
        'Billing -> Orders',
        'Direction 1 violation',
        'ERROR Laravents\Tests\Fixtures\Verify\Direction\Billing\Handlers\ChargeOnOrderPlaced uses Laravents\Tests\Fixtures\Verify\Direction\Orders\Models\Order (tests/Fixtures/Verify/Direction/Billing/Handlers/ChargeOnOrderPlaced.php:15).',
    ]]);
});

test('a class outside every module, in app/ or another scanned path, reaches a module only through its Api', function (): void {
    verifyFixture('tests/Fixtures/Verify/Outside/Modules', 'Laravents\Tests\Fixtures\Verify\Outside\Modules');
    app()->useAppPath(base_path('tests/Fixtures/Verify/Outside/App'));

    expect(verifyRows())->toBe([1, [
        'Module graph 1 module',
        'Orders',
        'Direction 1 violation',
        'ERROR Laravents\Tests\Fixtures\Verify\Outside\App\Support\Receipts uses Laravents\Tests\Fixtures\Verify\Outside\Modules\Orders\Models\Order (tests/Fixtures/Verify/Outside/App/Support/Receipts.php:7).',
    ]]);
});

test('an Api class referencing its own module outside the Api is an Api purity violation', function (): void {
    verifyFixture('Purity');

    expect(verifyRows())->toBe([1, [
        'Module graph 2 modules',
        'Billing -> Orders',
        'Api purity 1 violation',
        'ERROR Laravents\Tests\Fixtures\Verify\Purity\Orders\Api\OrderPlaced uses Laravents\Tests\Fixtures\Verify\Purity\Orders\Models\Order (tests/Fixtures/Verify/Purity/Orders/Api/OrderPlaced.php:7).',
    ]]);
});

test('a cycle fails, and --allow-cycles demotes it to a warning', function (): void {
    verifyFixture('Cycle');

    expect(verifyRows())->toBe([1, [
        'Module graph 2 modules',
        'Billing -> Orders',
        'Orders -> Billing',
        'Cycles 1 cycle',
        'ERROR Billing -> Orders -> Billing.',
    ]]);

    expect(verifyRows(['--allow-cycles' => true]))->toBe([0, [
        'Module graph 2 modules',
        'Billing -> Orders',
        'Orders -> Billing',
        'Cycles 1 cycle (allowed)',
        'WARN Billing -> Orders -> Billing.',
        'INFO 2 modules verified, no violations (1 cycle allowed).',
    ]]);
});

test('a shared kernel module passes: every module may use its Api, and the user is reached through Laravel and the application', function (): void {
    verifyFixture('Kernel');

    expect(verifyRows())->toBe([0, [
        'Module graph 3 modules',
        'Billing -> Orders',
        'Billing -> Shared',
        'Orders -> Shared',
        'INFO 3 modules verified, no violations.',
    ]]);
});

test('a shared kernel whose Api leans on its own internals, or that listens to a module using it, fails', function (): void {
    verifyFixture('KernelLeak');

    expect(verifyRows())->toBe([1, [
        'Module graph 2 modules',
        'Orders -> Shared',
        'Shared -> Orders',
        'Api purity 1 violation',
        'ERROR Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Api\Money uses Laravents\Tests\Fixtures\Verify\KernelLeak\Shared\Support\Rounding (tests/Fixtures/Verify/KernelLeak/Shared/Api/Money.php:7).',
        'Cycles 1 cycle',
        'ERROR Orders -> Shared -> Orders.',
    ]]);
});

test('--json gives the modules, the graph and every violation with its file and line', function (): void {
    verifyFixture('Direction');

    expect(Artisan::call('laravents:verify', ['--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'modules' => ['Billing', 'Orders'],
            'graph' => ['Billing' => ['Orders'], 'Orders' => []],
            'violations' => [[
                'rule' => 'direction',
                'severity' => 'error',
                'message' => 'Laravents\Tests\Fixtures\Verify\Direction\Billing\Handlers\ChargeOnOrderPlaced uses Laravents\Tests\Fixtures\Verify\Direction\Orders\Models\Order (tests/Fixtures/Verify/Direction/Billing/Handlers/ChargeOnOrderPlaced.php:15)',
                'class' => ChargeOnOrderPlaced::class,
                'uses' => Order::class,
                'file' => 'tests/Fixtures/Verify/Direction/Billing/Handlers/ChargeOnOrderPlaced.php',
                'line' => 15,
            ]],
            'passed' => false,
        ]);

    verifyFixture('Cycle');

    expect(Artisan::call('laravents:verify', ['--json' => true, '--allow-cycles' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'violations' => [[
                'rule' => 'cycle',
                'severity' => 'warning',
                'message' => 'Billing -> Orders -> Billing',
                'class' => null,
                'uses' => null,
                'file' => null,
                'line' => null,
            ]],
            'passed' => true,
        ]);

    verifyFixture('Clean');

    expect(Artisan::call('laravents:verify', ['--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'modules' => ['Billing', 'Orders'],
            'graph' => ['Billing' => ['Orders'], 'Orders' => []],
            'violations' => [],
            'passed' => true,
        ]);
});

test('a discovery error is reported under Registry and fails', function (): void {
    $paths = [dirname(__DIR__, 2).'/Fixtures/Discovery/Invalid'];
    $message = caught(DiscoveryException::class, static fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build(ClassDiscovery::in($paths)))->getMessage();
    verifyFixture('tests/Fixtures/Discovery/Invalid', 'Laravents\Tests\Fixtures\Discovery');

    [$code, $rows] = verifyRows();

    expect($code)->toBe(1)
        ->and(array_slice($rows, -2))->toBe(['Registry 1 violation', 'ERROR '.str_replace(base_path().'/', '', $message)]);
});
