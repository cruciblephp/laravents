<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravents\Discovery\ClassDiscovery;
use Laravents\Discovery\ModuleCanvas;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\ExpressOrderPlaced;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderArchived;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderCancelled;
use Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced;
use Laravents\Tests\Fixtures\Patterns\Audit\Handlers\OpenedAudit as PatternOpenedAudit;
use Laravents\Tests\Fixtures\Patterns\Chat\Api\ChatOpened as PatternChatOpened;
use Laravents\Tests\Fixtures\Patterns\Chat\Handlers\ChatAudit as PatternChatAudit;
use Laravents\Tests\Fixtures\Patterns\Room\Api\RoomOpened;
use Laravents\Verify\DependencyScanner;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\MemberReplaced;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Api\ReplaceMember;
use Modules\Band\Models\Lineup;
use Modules\Band\Models\Nickname;
use Modules\Press\Api\PrintHeadline;

/**
 * The rendered rows, trimmed, with twoColumnDetail()'s dot leader and any run of spaces (both depend on the terminal width)
 * folded into one space.
 *
 * @return list<string>
 */
function shownRows(string $module): array
{
    expect(Artisan::call('laravents:show', ['module' => $module]))->toBe(0);

    return array_map(
        static fn (string $line): string => trim((string) preg_replace(['/ \.{2,}( |$)/', '/ {2,}/'], ' ', $line)),
        explode("\n", trim(Artisan::output())),
    );
}

test('laravents:show Band prints the Band canvas', function (): void {
    expect(shownRows('Band'))->toBe([
        'Band',
        'Namespace Modules\Band',
        '',
        'Commands handler / message / http',
        'band.give_nickname Authorize, Transactional Modules\Band\Handlers\BandHandlers@give / Modules\Band\Api\GiveNickname / POST /laravents/commands/band.give_nickname',
        'band.replace_member Authorize, Transactional Modules\Band\Handlers\BandHandlers@replace / Modules\Band\Api\ReplaceMember / -',
        '',
        'Queries handler / message / http',
        'band.get_nickname Modules\Band\Handlers\BandHandlers@get / Modules\Band\Api\GetNickname / GET /laravents/queries/band.get_nickname',
        '',
        'Events published listener / module / queue',
        'Modules\Band\Api\MemberReplaced Modules\FanClub\Handlers\ArchiveBandEvents@__invoke / FanClub / sync',
        'Modules\Band\Api\MemberReplaced from Lineup (created)',
        'Modules\Band\Api\NicknameGiven Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke / Press / press',
        'Modules\Band\Api\NicknameGiven Modules\FanClub\Handlers\ArchiveBandEvents@__invoke / FanClub / sync',
        'Modules\Band\Api\NicknameGiven from Nickname (created)',
        '',
        'Events consumed handler / module / queue',
        '',
        'Depends on classes',
    ]);
});

test('laravents:show Press prints the Press canvas', function (): void {
    expect(shownRows('Press'))->toBe([
        'Press',
        'Namespace Modules\Press',
        '',
        'Commands handler / message / http',
        'press.print_headline Authorize, Transactional Modules\Press\Handlers\PressHandlers@print / Modules\Press\Api\PrintHeadline / -',
        '',
        'Queries handler / message / http',
        '',
        'Events published listener / module / queue',
        '',
        'Events consumed handler / module / queue',
        'Modules\Band\Api\NicknameGiven Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke / Band / press',
        '',
        'Depends on classes',
        'Band 1 class',
    ]);
});

test('laravents:show FanClub prints the FanClub canvas: a pattern and a framework event consumed, and no dependency', function (): void {
    expect(shownRows('FanClub'))->toBe([
        'FanClub',
        'Namespace Modules\FanClub',
        '',
        'Commands handler / message / http',
        'fan_club.welcome_fan Authorize, Transactional Modules\FanClub\Handlers\FanClubHandlers@welcome / Modules\FanClub\Api\WelcomeFan / -',
        '',
        'Queries handler / message / http',
        '',
        'Events published listener / module / queue',
        '',
        'Events consumed handler / module / queue',
        'Modules\Band\Api\MemberReplaced Modules\FanClub\Handlers\ArchiveBandEvents@__invoke / Band / sync',
        'Modules\Band\Api\NicknameGiven Modules\FanClub\Handlers\ArchiveBandEvents@__invoke / Band / sync',
        'Illuminate\Auth\Events\Registered Modules\FanClub\Handlers\WelcomeOnRegistered@__invoke / Laravel / fan_club',
        '',
        'Depends on classes',
    ]);
});

test('laravents:show --json prints one object with every section', function (): void {
    expect(Artisan::call('laravents:show', ['module' => 'Press', '--json' => true]))->toBe(0);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'module' => 'Press',
        'namespace' => 'Modules\Press',
        'commands' => [[
            'routing_key' => 'press.print_headline',
            'handler' => 'Modules\Press\Handlers\PressHandlers@print',
            'message' => PrintHeadline::class,
            'interceptors' => ['Authorize', 'Transactional'],
            'http' => null,
        ]],
        'queries' => [],
        'events_published' => [],
        'events_consumed' => [[
            'event' => NicknameGiven::class,
            'handler' => 'Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke',
            'module' => 'Band',
            'mode' => 'async',
            'queue' => 'press',
        ]],
        'depends_on' => [['module' => 'Band', 'classes' => 1, 'outside_api' => 0]],
    ]);

    Artisan::call('laravents:show', ['module' => 'Band', '--json' => true]);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
        'commands' => [[
            'routing_key' => 'band.give_nickname',
            'handler' => 'Modules\Band\Handlers\BandHandlers@give',
            'message' => GiveNickname::class,
            'interceptors' => ['Authorize', 'Transactional'],
            'http' => 'POST /laravents/commands/band.give_nickname',
        ], [
            'routing_key' => 'band.replace_member',
            'handler' => 'Modules\Band\Handlers\BandHandlers@replace',
            'message' => ReplaceMember::class,
            'interceptors' => ['Authorize', 'Transactional'],
            'http' => null,
        ]],
        'events_published' => [[
            'event' => MemberReplaced::class,
            'listeners' => [[
                'handler' => 'Modules\FanClub\Handlers\ArchiveBandEvents@__invoke',
                'module' => 'FanClub',
                'mode' => 'sync',
                'queue' => null,
            ]],
            'from' => [['model' => Lineup::class, 'hook' => 'created']],
        ], [
            'event' => NicknameGiven::class,
            'listeners' => [[
                'handler' => 'Modules\Press\Handlers\HeadlineOnNicknameGiven@__invoke',
                'module' => 'Press',
                'mode' => 'async',
                'queue' => 'press',
            ], [
                'handler' => 'Modules\FanClub\Handlers\ArchiveBandEvents@__invoke',
                'module' => 'FanClub',
                'mode' => 'sync',
                'queue' => null,
            ]],
            'from' => [['model' => Nickname::class, 'hook' => 'created']],
        ]]]);
});

test('an unknown module exits 1 and lists the known ones', function (): void {
    expect(Artisan::call('laravents:show', ['module' => 'Billing']))->toBe(1)
        ->and(Artisan::output())->toContain('Module [Billing] not found. Known modules: Band, FanClub, Press.');
});

test('the canvas lists unheard events, every listener, and skips messages', function (): void {
    $paths = [dirname(__DIR__, 2).'/Fixtures/Discovery/Shop', dirname(__DIR__, 2).'/Fixtures/Discovery/Listeners'];
    $classes = ClassDiscovery::in($paths);
    $canvas = new ModuleCanvas(
        (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build($classes),
        $classes,
        'Laravents\Tests\Fixtures\Discovery',
        (new DependencyScanner('Laravents\Tests\Fixtures\Discovery'))->scan(ClassDiscovery::structures($paths)),
    );
    $shop = $canvas->for('Shop');
    $listeners = $canvas->for('Listeners');

    expect($canvas->modules())->toBe(['Listeners', 'Shop'])
        ->and(array_column($shop['events_published'], 'event'))->toBe([
            ExpressOrderPlaced::class,
            OrderArchived::class,
            OrderCancelled::class,
            OrderPlaced::class,
        ])
        ->and($shop['events_published'][1]['listeners'])->toBe([])
        ->and(array_column($shop['events_published'][3]['listeners'], 'queue', 'handler'))->toBe([
            'Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners@onAnyOrderEvent' => null,
            'Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners@onPlaced' => null,
            'Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners@later' => 'listeners',
            'Laravents\Tests\Fixtures\Discovery\Listeners\OrderListeners@mail' => 'mail',
        ])
        ->and($shop['events_consumed'])->toBe([])
        ->and($shop['depends_on'])->toBe([])
        ->and(array_column($listeners['events_consumed'], 'module'))->each->toBe('Shop')
        ->and($listeners['events_consumed'])->toHaveCount(6)
        ->and($listeners['depends_on'])->toBe([['module' => 'Shop', 'classes' => 2, 'outside_api' => 0]]);
});

test('a command message is not an event even without a handler, a Publishable result is one, and a keyed handler consumes nothing from Laravel', function (): void {
    $paths = [dirname(__DIR__, 2).'/Fixtures/Canvas'];
    $classes = ClassDiscovery::in($paths);
    $root = 'Laravents\Tests\Fixtures\Canvas';
    $canvas = new ModuleCanvas((new RegistryBuilder($root))->build($classes), $classes, $root, (new DependencyScanner($root))->scan(ClassDiscovery::structures($paths)));

    expect(array_column($canvas->for('Gig')['events_published'], 'event'))->toBe([$root.'\Gig\Api\GigView'])
        ->and($canvas->for('Fans')['events_consumed'])->toBe([]);
});

test('Depends on is the verify graph: every reference, with the ones outside the Api marked', function (): void {
    config([
        'laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Verify/Direction'],
        'laravents.modules.namespace' => 'Laravents\Tests\Fixtures\Verify\Direction',
    ]);

    expect(array_slice(shownRows('Billing'), -2))->toBe([
        'Depends on classes',
        'Orders 1 class / 1 outside Api',
    ]);

    Artisan::call('laravents:show', ['module' => 'Billing', '--json' => true]);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
        'depends_on' => [['module' => 'Orders', 'classes' => 1, 'outside_api' => 1]],
    ]);

    config([
        'laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Verify/Clean'],
        'laravents.modules.namespace' => 'Laravents\Tests\Fixtures\Verify\Clean',
    ]);
    app()->forgetInstance(Registry::class);

    expect(array_slice(shownRows('Billing'), -2))->toBe([
        'Depends on classes',
        'Orders 1 class',
    ]);
});

test('the canvas lists a pattern handler under each published event its pattern covers, in discovery order, and as consumed from each other module', function (): void {
    $paths = [dirname(__DIR__, 2).'/Fixtures/Patterns'];
    $classes = ClassDiscovery::in($paths);
    $canvas = new ModuleCanvas(
        (new RegistryBuilder('Laravents\Tests\Fixtures\Patterns'))->build($classes),
        $classes,
        'Laravents\Tests\Fixtures\Patterns',
        (new DependencyScanner('Laravents\Tests\Fixtures\Patterns'))->scan(ClassDiscovery::structures($paths)),
    );

    expect($canvas->modules())->toBe(['Audit', 'Chat', 'Room'])
        ->and($canvas->for('Chat')['events_published'])->toBe([[
            'event' => PatternChatOpened::class,
            'listeners' => [
                ['handler' => PatternOpenedAudit::class.'@anythingOpened', 'module' => 'Audit', 'mode' => 'sync', 'queue' => null],
                ['handler' => PatternChatAudit::class.'@everyChatEvent', 'module' => 'Chat', 'mode' => 'sync', 'queue' => null],
            ],
            'from' => [],
        ]])
        ->and(array_column($canvas->for('Room')['events_published'][0]['listeners'], 'handler'))->toBe([
            PatternOpenedAudit::class.'@anythingOpened',
            PatternOpenedAudit::class.'@roomLater',
        ])
        ->and($canvas->for('Chat')['events_consumed'])->toBe([])
        ->and($canvas->for('Audit')['events_consumed'])->toBe([
            ['event' => PatternChatOpened::class, 'handler' => PatternOpenedAudit::class.'@anythingOpened', 'module' => 'Chat', 'mode' => 'sync', 'queue' => null],
            ['event' => RoomOpened::class, 'handler' => PatternOpenedAudit::class.'@anythingOpened', 'module' => 'Room', 'mode' => 'sync', 'queue' => null],
            ['event' => RoomOpened::class, 'handler' => PatternOpenedAudit::class.'@roomLater', 'module' => 'Room', 'mode' => 'async', 'queue' => 'audit'],
        ])
        ->and($canvas->for('Audit')['depends_on'])->toBe([]);
});
