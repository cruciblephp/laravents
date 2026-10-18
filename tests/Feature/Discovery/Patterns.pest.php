<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Discovery\RegistryCache;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Facades\Laravents;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Discovery\Invalid;
use Laravents\Tests\Fixtures\Patterns\Audit\Handlers\OpenedAudit;
use Laravents\Tests\Fixtures\Patterns\Chat\Api\ChatOpened;
use Laravents\Tests\Fixtures\Patterns\Chat\Handlers\ChatAudit;
use Laravents\Tests\Fixtures\Patterns\Room\Api\RoomOpened;
use Laravents\Tests\Fixtures\Recorder;
use Modules\Band\Api\NicknameGiven;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

function patternRegistry(): Registry
{
    return (new RegistryBuilder('Laravents\Tests\Fixtures\Patterns'))->build([ChatAudit::class, OpenedAudit::class]);
}

beforeEach(function (): void {
    Recorder::reset();
    app()->instance(Registry::class, patternRegistry());
});

test('chat.* receives a keyed chat.open and an object ChatOpened by its derived key, and not notification.send', function (): void {
    Laravents::publish('chat.open', ['chatId' => 1]);
    Laravents::publish(new ChatOpened(2));
    Laravents::publish('notification.send', ['userId' => 3]);

    expect(Recorder::calls())->toEqual([
        ['chat.open', ['chatId' => 1]],
        ['chat.*', ['chatId' => 1]],
        ['chat.*', new ChatOpened(2)],
        ['*.opened', new ChatOpened(2)],
    ])
        ->and(app(Registry::class)->routingKeyFor(ChatOpened::class, HandlerKind::Event))->toBe('chat.opened');
});

test('a pattern handler and an exact handler of the same key both run, once each, the exact one first although discovered second', function (): void {
    expect(array_slice(array_map(static fn (HandlerDefinition $handler): string => $handler->method, app(Registry::class)->all()), 0, 2))
        ->toBe(['everyChatEvent', 'onOpen']);

    Laravents::publish('chat.open', ['chatId' => 1]);

    expect(Recorder::handlers())->toBe(['chat.open', 'chat.*']);
});

test('*.opened in one module reaches the opened events of two others, keyed and as objects', function (): void {
    Laravents::publish(new ChatOpened(1));
    Laravents::publish(new RoomOpened(2));
    Laravents::publish('room.opened', ['roomId' => 3]);
    Laravents::publish('room.closed', ['roomId' => 4]);

    expect(array_values(array_filter(Recorder::calls(), static fn (array $call): bool => $call[0] === '*.opened')))->toEqual([
        ['*.opened', new ChatOpened(1)],
        ['*.opened', new RoomOpened(2)],
        ['*.opened', ['roomId' => 3]],
    ]);
});

test('an asynchronous pattern handler of a keyed publish writes its outbox row and the worker delivers it as the publisher', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Laravents::publish('room.opened', ['roomId' => 7]);

    $row = EventPublication::query()->sole();

    expect($row->listener)->toBe(OpenedAudit::class.'@roomLater')
        ->and($row->completed_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(Recorder::handlers())->toBe(['*.opened']);

    runWorker('audit');

    expect(Recorder::calls()[1])->toBe(['room.* later', [['roomId' => 7], $user->id]])
        ->and($row->refresh()->completed_at)->not->toBeNull();
});

test('discovery refuses a routing-key pattern that breaks a rule, and says which', function (string $class, string $message): void {
    /** @var class-string $class */
    expect(fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Discovery'))->build([$class]))
        ->toThrow(DiscoveryException::class, $message);
})->with([
    '* inside a segment' => [Invalid\PatternInsideSegment::class, 'has the routing-key pattern [cha*.open] with * inside the segment [cha*], but * stands for one whole dotted segment'],
    'no literal segment' => [Invalid\PatternWithoutLiteral::class, 'has the routing-key pattern [*.*] with no literal segment, but a pattern names at least one, as in chat.* or *.opened'],
    'fewer than two segments' => [Invalid\PatternOneSegment::class, 'has the routing-key pattern [*] with fewer than two segments, but a pattern names at least one literal segment and one *, as in chat.*'],
    'an empty segment' => [Invalid\PatternEmptySegment::class, 'has the routing-key pattern [chat..*] with an empty segment'],
    '#' => [Invalid\PatternHash::class, 'has the routing-key pattern [chat.#] with [#], but a pattern knows one operator: * standing for one whole dotted segment'],
    'another operator' => [Invalid\PatternOtherOperator::class, 'has the routing-key pattern [chat.open?] with [?], but a pattern knows one operator: * standing for one whole dotted segment'],
    'a command handler' => [Invalid\PatternCommandHandler::class, 'has the routing-key pattern [shop.*], but a pattern is only allowed on an event handler: a command or query has exactly one handler'],
    'a query handler' => [Invalid\PatternQueryHandler::class, 'has the routing-key pattern [shop.*], but a pattern is only allowed on an event handler: a command or query has exactly one handler'],
    'a typed first parameter' => [Invalid\PatternTypedParameter::class, 'has the routing-key pattern [shop.*] and takes [Laravents\Tests\Fixtures\Discovery\Shop\Api\OrderPlaced], but a pattern handler takes the payload as published (object|array or mixed): a pattern covers keyed and object events alike'],
]);

/**
 * One memo of the registry, read without resolving anything: each entry's handler methods.
 *
 * @return array<string, list<string>>
 */
function patternMemo(Registry $registry, string $property): array
{
    $memo = (new ReflectionProperty(Registry::class, $property))->getValue($registry);
    $methods = [];

    foreach (is_array($memo) ? $memo : throw new RuntimeException('The memo is not an array.') as $key => $handlers) {
        $methods[(string) $key] = array_map(
            static fn (mixed $handler): string => $handler instanceof HandlerDefinition ? $handler->method : throw new RuntimeException('The memo holds something other than handlers.'),
            is_array($handlers) ? array_values($handlers) : [],
        );
    }

    return $methods;
}

test('a key and an event class are resolved once per process: a second publish matches nothing again', function (): void {
    $registry = app(Registry::class);

    expect(patternMemo($registry, 'resolvedKeyHandlers'))->toBe([])
        ->and(patternMemo($registry, 'resolvedObjectHandlers'))->toBe([]);

    Laravents::publish('chat.open', ['chatId' => 1]);
    Laravents::publish(new ChatOpened(2));

    expect(patternMemo($registry, 'resolvedKeyHandlers'))->toBe(['chat.open' => ['onOpen', 'everyChatEvent']])
        ->and(patternMemo($registry, 'resolvedObjectHandlers'))->toBe([ChatOpened::class => ['everyChatEvent', 'anythingOpened']]);

    // Empty the resolved lists: a second publish that matched again would still reach the handlers.
    (new ReflectionProperty(Registry::class, 'resolvedKeyHandlers'))->setValue($registry, ['chat.open' => []]);
    (new ReflectionProperty(Registry::class, 'resolvedObjectHandlers'))->setValue($registry, [ChatOpened::class => []]);
    Recorder::reset();

    Laravents::publish('chat.open', ['chatId' => 3]);
    Laravents::publish(new ChatOpened(4));

    expect(Recorder::calls())->toBe([])
        ->and(patternMemo($registry, 'resolvedKeyHandlers'))->toBe(['chat.open' => []]);
});

test('the registry cache keeps each pattern compiled on its definition', function (): void {
    $cache = new RegistryCache(storage_path('framework/testing/laravents-patterns.php'));
    $cache->write(patternRegistry());
    $loaded = $cache->load();
    $cache->clear();

    expect($loaded->handler(ChatAudit::class.'@everyChatEvent')?->pattern)->toBe('/^chat\.[^.]+$/')
        ->and($loaded->handler(ChatAudit::class.'@everyChatEvent')?->routingKey)->toBe('chat.*')
        ->and($loaded->handler(OpenedAudit::class.'@anythingOpened')?->pattern)->toBe('/^[^.]+\.opened$/')
        ->and($loaded->handler(ChatAudit::class.'@onOpen')?->pattern)->toBeNull()
        ->and(array_map(static fn (HandlerDefinition $handler): string => $handler->method, $loaded->eventHandlersForKey('room.opened')))
        ->toBe(['anythingOpened', 'roomLater']);
});

test('the workbench archives every Band event in FanClub: NicknameGiven by its key, a keyed band event as published', function (): void {
    app()->forgetInstance(Registry::class);
    // The suite's log fake, as Logging.pest.php uses it: a Monolog TestHandler as the default channel.
    config([
        'logging.channels.laravents_test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.default' => 'laravents_test',
    ]);

    Laravents::publish(new NicknameGiven(1, 1, 2, 'The Quiet One'));
    Laravents::publish('band.split', ['year' => 1970]);

    $logger = Log::channel('laravents_test');
    $this->assertInstanceOf(Logger::class, $logger);
    $monolog = $logger->getLogger();
    $this->assertInstanceOf(Monolog::class, $monolog);
    $handler = $monolog->getHandlers()[0];
    $this->assertInstanceOf(TestHandler::class, $handler);

    expect(array_map(static fn (LogRecord $record): array => [$record->message, $record->context['event'] ?? null], $handler->getRecords()))->toBe([
        ['Band event', NicknameGiven::class],
        ['Band event', ['year' => 1970]],
    ]);
});

test('a native event has no key, so no pattern reaches it', function (): void {
    expect(app(Registry::class)->routingKeyFor(stdClass::class, HandlerKind::Event))->toBeNull()
        ->and(app(Registry::class)->eventHandlersForObject(new stdClass))->toBe([]);
});
