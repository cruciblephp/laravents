<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;
use Laravents\Attributes\Around;
use Laravents\Attributes\Asynchronous;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\EventHandler;
use Laravents\Attributes\QueryHandler;
use Laravents\Attributes\Transactional;
use Laravents\Discovery\AppliedInterceptor;
use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\InterceptorDefinition;
use Laravents\Discovery\InterceptorKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Interceptors\AuthorizationInterceptor;
use Laravents\Interceptors\TransactionInterceptor;
use Laravents\LaraventsServiceProvider;
use Laravents\Tests\Fixtures\Generators\Scratch;

use function Orchestra\Testbench\default_skeleton_path;

/*
 * Every generator runs against a scratch base path under the system temp dir (Laravents\Tests\Fixtures\Generators\Scratch),
 * with a modules namespace unique to the test: nothing is written into the repository.
 */

beforeEach(function (): void {
    Scratch::create();
});

afterEach(function (): void {
    Scratch::destroy();
});

/**
 * Runs a generator and returns its output.
 *
 * @param  array<mixed>  $arguments  As Artisan::call() takes them
 */
function generate(string $command, array $arguments = []): string
{
    Artisan::call($command, $arguments);

    return Artisan::output();
}

/** `php -l` in its own process: the file is valid PHP. */
function expectCompiles(string $path): void
{
    $result = Process::run([PHP_BINARY, '-l', $path]);

    // On failure the expectation prints php -l's own message.
    expect($result->output().$result->errorOutput())->toContain('No syntax errors detected');
}

/** The registry the scratch modules build, discovered as the service provider discovers the real ones. */
function scratchRegistry(): Registry
{
    return (new RegistryBuilder(Scratch::$namespace))->build(ClassDiscovery::in([Scratch::path('modules')]));
}

test('laravents:module creates the directories, the provider, a test, and registers the provider', function (): void {
    $output = generate('laravents:module', ['name' => 'Tmp']);
    $provider = Scratch::class('Tmp\TmpServiceProvider');

    foreach (['Api', 'Handlers', 'database/migrations'] as $directory) {
        expect(Scratch::path('modules/Tmp/'.$directory.'/.gitkeep'))->toBeFile();
    }

    expect($output)->toContain('created successfully')
        ->and(File::get(Scratch::path('modules/Tmp/TmpServiceProvider.php')))
        ->toContain('namespace '.Scratch::$namespace.'\Tmp;')
        ->toContain('final class TmpServiceProvider extends ServiceProvider')
        ->toContain("\$this->loadMigrationsFrom(__DIR__.'/database/migrations');")
        ->and(File::get(Scratch::path('tests/Feature/Modules/TmpTest.php')))
        ->toContain('final class TmpTest extends TestCase')
        ->toContain("Artisan::call('laravents:list', ['--module' => 'Tmp'])")
        ->and(require Scratch::path('bootstrap/providers.php'))->toContain($provider);

    expectCompiles(Scratch::path('modules/Tmp/TmpServiceProvider.php'));
    expectCompiles(Scratch::path('tests/Feature/Modules/TmpTest.php'));

    // The provider is a real one: it loads.
    expect(new ($provider)(app()))->toBeInstanceOf(ServiceProvider::class);
});

test('the module test is PHPUnit unless the application uses Pest, as make:test decides; --pest and --phpunit choose', function (): void {
    File::ensureDirectoryExists(Scratch::path('tests'));
    File::put(Scratch::path('tests/Pest.php'), "<?php\n");

    // tests/Pest.php alone is not Pest: make:test also asks for Pest's own \Pest\version().
    generate('laravents:module', ['name' => 'Tmp']);
    expect(File::get(Scratch::path('tests/Feature/Modules/TmpTest.php')))->toContain('extends TestCase');

    generate('laravents:module', ['name' => 'Tmp', '--force' => true, '--pest' => true]);
    $pest = File::get(Scratch::path('tests/Feature/Modules/TmpTest.php'));
    expect($pest)->toContain("test('the Tmp module lists its handlers'")->not->toContain('class ');
    expectCompiles(Scratch::path('tests/Feature/Modules/TmpTest.php'));

    generate('laravents:module', ['name' => 'Tmp', '--force' => true, '--pest' => true, '--phpunit' => true]);
    expect(File::get(Scratch::path('tests/Feature/Modules/TmpTest.php')))->toContain('final class TmpTest extends TestCase');
});

test('laravents:command writes the Api command and its handler, which discovery registers', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    generate('laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp']);

    $command = File::get(Scratch::path('modules/Tmp/Api/PlaceOrder.php'));
    $handler = File::get(Scratch::path('modules/Tmp/Handlers/PlaceOrderHandler.php'));

    expect($command)
        ->toContain('namespace '.Scratch::$namespace.'\Tmp\Api;')
        ->toContain('use Laravents\Concerns\Sendable;')
        ->toContain('Command tmp.place_order.')
        ->toContain('final readonly class PlaceOrder')
        ->toContain('public function __construct(')
        ->and($handler)
        ->toContain('use '.Scratch::class('Tmp\Api\PlaceOrder').';')
        ->toContain('#[CommandHandler]')
        ->toContain('public function __invoke(PlaceOrder $command): void')
        ->not->toContain('#[Authorize]')
        ->not->toContain('#[Transactional]')
        ->not->toContain('{{');

    expectCompiles(Scratch::path('modules/Tmp/Api/PlaceOrder.php'));
    expectCompiles(Scratch::path('modules/Tmp/Handlers/PlaceOrderHandler.php'));

    $definition = scratchRegistry()->commandHandler('tmp.place_order');

    expect($definition?->handler())->toBe(Scratch::class('Tmp\Handlers\PlaceOrderHandler').'@__invoke')
        ->and($definition?->messageClass)->toBe(Scratch::class('Tmp\Api\PlaceOrder'))
        ->and($definition?->module)->toBe('Tmp')
        ->and((new ReflectionMethod((string) $definition?->class, '__invoke'))->getAttributes(CommandHandler::class))->toHaveCount(1);
});

test('laravents:command --authorize --transactional puts both attributes on the handler, and the built-in interceptors apply', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    generate('laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp', '--authorize' => true, '--transactional' => true]);

    $path = Scratch::path('modules/Tmp/Handlers/PlaceOrderHandler.php');

    expect(File::get($path))
        ->toContain("use Laravents\\Attributes\\Authorize;\nuse Laravents\\Attributes\\CommandHandler;\nuse Laravents\\Attributes\\Transactional;")
        ->toContain("    #[CommandHandler]\n    #[Authorize]\n    #[Transactional]\n    public function __invoke(");

    expectCompiles($path);

    $registry = scratchRegistry();
    $definition = $registry->commandHandler('tmp.place_order');
    $method = new ReflectionMethod((string) $definition?->class, '__invoke');
    $applied = array_map(static fn (AppliedInterceptor $interceptor): string => $interceptor->interceptor->class, $registry->interceptorsFor($definition ?? throw new RuntimeException('not discovered')));

    expect($method->getAttributes(Authorize::class))->toHaveCount(1)
        ->and($method->getAttributes(Transactional::class))->toHaveCount(1)
        ->and($applied)->toBe([AuthorizationInterceptor::class, TransactionInterceptor::class]);
});

test('laravents:query writes an Askable query and a query handler', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    generate('laravents:query', ['name' => 'GetOrder', '--module' => 'Tmp', '--authorize' => true]);

    expect(File::get(Scratch::path('modules/Tmp/Api/GetOrder.php')))
        ->toContain('use Laravents\Concerns\Askable;')
        ->toContain('Query tmp.get_order.')
        ->and(File::get(Scratch::path('modules/Tmp/Handlers/GetOrderHandler.php')))
        ->toContain('#[QueryHandler]')
        ->toContain('#[Authorize]')
        ->toContain('public function __invoke(GetOrder $query): mixed');

    expectCompiles(Scratch::path('modules/Tmp/Api/GetOrder.php'));
    expectCompiles(Scratch::path('modules/Tmp/Handlers/GetOrderHandler.php'));

    $definition = scratchRegistry()->queryHandler('tmp.get_order');

    expect($definition?->kind)->toBe(HandlerKind::Query)
        ->and((new ReflectionMethod((string) $definition?->class, '__invoke'))->getAttributes(QueryHandler::class))->toHaveCount(1);
});

test('laravents:event writes a Publishable event and a handler; --async without a queue uses the module\'s', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    generate('laravents:event', ['name' => 'OrderPlaced', '--module' => 'Tmp']);
    generate('laravents:event', ['name' => 'OrderShipped', '--module' => 'Tmp', '--async' => null]);
    generate('laravents:event', ['name' => 'OrderPaid', '--module' => 'Tmp', '--async' => 'billing']);

    expect(File::get(Scratch::path('modules/Tmp/Api/OrderPlaced.php')))
        ->toContain('use Laravents\Concerns\Publishable;')
        ->toContain('final readonly class OrderPlaced')
        ->and(File::get(Scratch::path('modules/Tmp/Handlers/OrderPlacedHandler.php')))
        ->toContain('#[EventHandler]')
        ->toContain('public function __invoke(OrderPlaced $event): void')
        ->not->toContain('Asynchronous')
        ->and(File::get(Scratch::path('modules/Tmp/Handlers/OrderShippedHandler.php')))
        ->toContain("#[EventHandler]\n    #[Asynchronous]\n")
        ->and(File::get(Scratch::path('modules/Tmp/Handlers/OrderPaidHandler.php')))
        ->toContain("#[Asynchronous('billing')]");

    foreach (['Api/OrderPlaced', 'Api/OrderShipped', 'Api/OrderPaid', 'Handlers/OrderPlacedHandler', 'Handlers/OrderShippedHandler', 'Handlers/OrderPaidHandler'] as $file) {
        expectCompiles(Scratch::path('modules/Tmp/'.$file.'.php'));
    }

    $registry = scratchRegistry();
    $queue = static fn (string $event): array => array_map(
        static fn (HandlerDefinition $handler): array => [$handler->handler(), $handler->queue],
        $registry->eventHandlersFor(Scratch::loaded('Tmp\Api\\'.$event)),
    );

    expect($queue('OrderPlaced'))->toBe([[Scratch::class('Tmp\Handlers\OrderPlacedHandler').'@__invoke', null]])
        ->and($queue('OrderShipped'))->toBe([[Scratch::class('Tmp\Handlers\OrderShippedHandler').'@__invoke', 'tmp']])
        ->and($queue('OrderPaid'))->toBe([[Scratch::class('Tmp\Handlers\OrderPaidHandler').'@__invoke', 'billing']])
        ->and((new ReflectionMethod(Scratch::class('Tmp\Handlers\OrderShippedHandler'), '__invoke'))->getAttributes(Asynchronous::class))->toHaveCount(1)
        ->and((new ReflectionMethod(Scratch::class('Tmp\Handlers\OrderPlacedHandler'), '__invoke'))->getAttributes(EventHandler::class))->toHaveCount(1);
});

test('laravents:interceptor --around --pointcut writes an Around taking a MethodInvocation, and its attribute pointcut', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    generate('laravents:interceptor', ['name' => 'RateLimit', '--module' => 'Tmp', '--around' => true, '--pointcut' => 'RateLimited']);
    generate('laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp']);

    $interceptor = Scratch::path('modules/Tmp/Interceptors/RateLimit.php');
    $pointcut = Scratch::path('modules/Tmp/Attributes/RateLimited.php');

    expect(File::get($interceptor))
        ->toContain('use '.Scratch::class('Tmp\Attributes\RateLimited').';')
        ->toContain('use Laravents\Invocation\MethodInvocation;')
        ->toContain('#[Around(pointcut: RateLimited::class)]')
        ->toContain('public function handle(MethodInvocation $invocation, RateLimited $rule): mixed')
        ->toContain('return $invocation->proceed();')
        ->and(File::get($pointcut))
        ->toContain('#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]')
        ->toContain('final readonly class RateLimited');

    expectCompiles($interceptor);
    expectCompiles($pointcut);

    // Put the pointcut on the generated handler, as a developer would: the interceptor applies to it.
    $handlerPath = Scratch::path('modules/Tmp/Handlers/PlaceOrderHandler.php');
    File::put($handlerPath, str_replace('#[CommandHandler]', "#[CommandHandler]\n    #[\\".Scratch::class('Tmp\Attributes\RateLimited').']', File::get($handlerPath)));

    $registry = scratchRegistry();
    $interceptors = array_values(array_filter($registry->interceptors(), static fn (InterceptorDefinition $definition): bool => $definition->class === Scratch::class('Tmp\Interceptors\RateLimit')));
    $applied = $registry->interceptorsFor($registry->commandHandler('tmp.place_order') ?? throw new RuntimeException('not discovered'));

    expect($interceptors)->toHaveCount(1)
        ->and($interceptors[0]->kind)->toBe(InterceptorKind::Around)
        ->and($interceptors[0]->pointcut)->toBe(Scratch::class('Tmp\Attributes\RateLimited'))
        ->and((new ReflectionMethod(Scratch::class('Tmp\Interceptors\RateLimit'), 'handle'))->getAttributes(Around::class))->toHaveCount(1)
        ->and(array_map(static fn (AppliedInterceptor $interceptor): string => $interceptor->interceptor->class, $applied))->toBe([Scratch::class('Tmp\Interceptors\RateLimit')]);
});

test('laravents:interceptor --before and --after; an existing attribute, an interface, or no --pointcut at all', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    $audit = generate('laravents:interceptor', ['name' => 'Audit', '--module' => 'Tmp', '--before' => true, '--pointcut' => Transactional::class]);
    generate('laravents:interceptor', ['name' => 'Measure', '--module' => 'Tmp', '--after' => true, '--pointcut' => 'Measured']);
    $trace = generate('laravents:interceptor', ['name' => 'Trace', '--module' => 'Tmp', '--before' => true, '--pointcut' => ShouldQueue::class]);
    generate('laravents:interceptor', ['name' => 'Throttle', '--module' => 'Tmp']);

    // An existing pointcut is never written, and one generated earlier is kept even with --force.
    File::append(Scratch::path('modules/Tmp/Attributes/Measured.php'), "// grown by hand\n");
    generate('laravents:interceptor', ['name' => 'Measure', '--module' => 'Tmp', '--after' => true, '--pointcut' => 'Measured', '--force' => true]);

    expect($audit)->not->toContain('Pointcut')
        ->and($trace)->not->toContain('Pointcut')
        ->and(File::files(Scratch::path('modules/Tmp/Attributes')))->toHaveCount(2)
        ->and(File::get(Scratch::path('modules/Tmp/Attributes/Measured.php')))->toEndWith("// grown by hand\n")
        ->and(Scratch::path('modules/Tmp/Attributes/Throttle.php'))->toBeFile()
        ->and(File::get(Scratch::path('modules/Tmp/Interceptors/Throttle.php')))
        ->toContain('use '.Scratch::class('Tmp\Attributes\Throttle').' as ThrottlePointcut;')
        ->toContain('#[Around(pointcut: ThrottlePointcut::class)]')
        ->toContain('public function handle(MethodInvocation $invocation, ThrottlePointcut $rule): mixed')
        ->and(File::get(Scratch::path('modules/Tmp/Interceptors/Trace.php')))
        ->toContain('use '.ShouldQueue::class.';')
        ->toContain('public function handle(Message $message): void')
        ->and(File::get(Scratch::path('modules/Tmp/Interceptors/Audit.php')))
        ->toContain('use '.Transactional::class.';')
        ->toContain('#[Before(pointcut: Transactional::class)]')
        ->toContain('public function handle(Message $message, Transactional $rule): void')
        ->and(File::get(Scratch::path('modules/Tmp/Interceptors/Measure.php')))
        ->toContain('#[After(pointcut: Measured::class)]')
        ->toContain('public function handle(mixed $result, Measured $rule): void')
        ->and(Scratch::path('modules/Tmp/Attributes/Transactional.php'))->not->toBeFile()
        ->and(Scratch::path('modules/Tmp/Attributes/Measured.php'))->toBeFile();

    expectCompiles(Scratch::path('modules/Tmp/Interceptors/Audit.php'));
    expectCompiles(Scratch::path('modules/Tmp/Interceptors/Measure.php'));
    expectCompiles(Scratch::path('modules/Tmp/Interceptors/Trace.php'));
    expectCompiles(Scratch::path('modules/Tmp/Interceptors/Throttle.php'));

    $kinds = array_map(
        static fn (InterceptorDefinition $definition): array => [$definition->class, $definition->kind],
        array_values(array_filter(scratchRegistry()->interceptors(), static fn (InterceptorDefinition $definition): bool => str_starts_with($definition->class, Scratch::$namespace))),
    );

    expect($kinds)->toBe([
        [Scratch::class('Tmp\Interceptors\Audit'), InterceptorKind::Before],
        [Scratch::class('Tmp\Interceptors\Measure'), InterceptorKind::After],
        [Scratch::class('Tmp\Interceptors\Throttle'), InterceptorKind::Around],
        [Scratch::class('Tmp\Interceptors\Trace'), InterceptorKind::Before],
    ]);
});

test('running a generator twice refuses to overwrite without --force, and overwrites with it', function (string $command, array $arguments, string $file): void {
    generate('laravents:module', ['name' => 'Tmp']);
    generate($command, $arguments);

    $path = Scratch::path($file);
    File::put($path, '<?php // edited by hand');

    expect(generate($command, $arguments))->toContain('already exists')
        ->and(File::get($path))->toBe('<?php // edited by hand');

    generate($command, [...$arguments, '--force' => true]);

    expect(File::get($path))->not->toBe('<?php // edited by hand');
    expectCompiles($path);
})->with([
    'module' => ['laravents:module', ['name' => 'Tmp'], 'modules/Tmp/TmpServiceProvider.php'],
    'command' => ['laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp'], 'modules/Tmp/Api/PlaceOrder.php'],
    'command handler' => ['laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp'], 'modules/Tmp/Handlers/PlaceOrderHandler.php'],
    'query' => ['laravents:query', ['name' => 'GetOrder', '--module' => 'Tmp'], 'modules/Tmp/Handlers/GetOrderHandler.php'],
    'event' => ['laravents:event', ['name' => 'OrderPlaced', '--module' => 'Tmp'], 'modules/Tmp/Api/OrderPlaced.php'],
    'interceptor' => ['laravents:interceptor', ['name' => 'RateLimit', '--module' => 'Tmp', '--pointcut' => 'RateLimited'], 'modules/Tmp/Interceptors/RateLimit.php'],
]);

test('a refused run writes nothing: an existing handler keeps a new command from being written', function (): void {
    generate('laravents:module', ['name' => 'Tmp']);
    File::put(Scratch::path('modules/Tmp/Handlers/PlaceOrderHandler.php'), '<?php // hand-written');

    expect(generate('laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp']))->toContain('Handler')->toContain('already exists')
        ->and(Scratch::path('modules/Tmp/Api/PlaceOrder.php'))->not->toBeFile();
});

test('message generators need an existing module', function (): void {
    expect(generate('laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp']))->toContain('Module [Tmp] does not exist')
        ->and(generate('laravents:query', ['name' => 'GetOrder']))->toContain('The --module option is required')
        ->and(Scratch::path('modules'))->not->toBeDirectory();
});

test('a published stub in stubs/laravents/ wins over the package\'s', function (): void {
    File::ensureDirectoryExists(Scratch::path('stubs/laravents'));
    File::put(Scratch::path('stubs/laravents/command.stub'), File::get(dirname(__DIR__, 3).'/stubs/command.stub').'// published stub'."\n");

    generate('laravents:module', ['name' => 'Tmp']);
    generate('laravents:command', ['name' => 'PlaceOrder', '--module' => 'Tmp']);

    expect(File::get(Scratch::path('modules/Tmp/Api/PlaceOrder.php')))->toContain('// published stub')
        ->and(File::get(Scratch::path('modules/Tmp/Handlers/PlaceOrderHandler.php')))->not->toContain('// published stub');
});

test('the stubs are publishable under the laravents-stubs tag, into stubs/laravents', function (): void {
    $paths = ServiceProvider::pathsToPublish(LaraventsServiceProvider::class, 'laravents-stubs');
    $source = (string) array_key_first($paths);

    // The target is under the application's base path at boot, which under Testbench is its skeleton.
    expect(array_values($paths))->toBe([default_skeleton_path().'/stubs/laravents'])
        ->and(realpath($source))->toBe(realpath(dirname(__DIR__, 3).'/stubs'))
        ->and(array_map(static fn (SplFileInfo $file): string => $file->getFilename(), File::files($source)))->toContain(
            'command.stub', 'command-handler.stub', 'query.stub', 'query-handler.stub', 'event.stub', 'event-handler.stub',
            'interceptor.around.stub', 'interceptor.before.stub', 'interceptor.after.stub', 'pointcut.stub', 'provider.stub', 'module-test.stub', 'module-test.pest.stub',
        );
});
