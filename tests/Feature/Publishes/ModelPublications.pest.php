<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravents\Bus\ModelEvents;
use Laravents\Discovery\ModelPublication;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Discovery\RegistryCache;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Facades\Laravents;
use Laravents\LaraventsServiceProvider;
use Laravents\Outbox\EventPublication;
use Laravents\Tests\Fixtures\Publishes\Invalid\Unbuildable\Shelf;
use Laravents\Tests\Fixtures\Publishes\Invalid\UnknownHook\Drawer;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookRemoved;
use Laravents\Tests\Fixtures\Publishes\Library\Api\BookShelved;
use Laravents\Tests\Fixtures\Publishes\Library\Handlers\LibraryListeners;
use Laravents\Tests\Fixtures\Publishes\Library\Models\Book;
use Laravents\Tests\Fixtures\Recorder;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\MemberReplaced;
use Modules\Band\Api\NicknameGiven;
use Modules\Band\Models\Lineup;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

afterEach(function (): void {
    app(RegistryCache::class)->clear();
});

/** The Library fixture's registry in place of the app's, its model events subscribed as boot subscribes the app's. */
function library(): Registry
{
    Recorder::reset();
    Book::createTable();
    $registry = (new RegistryBuilder('Laravents\Tests\Fixtures\Publishes'))->build([Book::class, LibraryListeners::class]);
    app()->instance(Registry::class, $registry);
    app(ModelEvents::class)->subscribe($registry, app('events'));

    return $registry;
}

test('the registry lists each (model, hook, event), and the app has Lineup and Nickname publishing on created', function (): void {
    expect(array_map(
        static fn (ModelPublication $publication): array => [$publication->model, $publication->hook, $publication->event, $publication->fromModel],
        app(Registry::class)->modelPublications(),
    ))->toBe([
        [Lineup::class, 'created', MemberReplaced::class, true],
        [Nickname::class, 'created', NicknameGiven::class, true],
    ])
        ->and(array_map(
            static fn (ModelPublication $publication): array => [$publication->hook, $publication->event, $publication->fromModel, $publication->eloquentEvent()],
            library()->modelPublications(),
        ))->toBe([
            ['created', BookShelved::class, false, 'eloquent.created: '.Book::class],
            ['deleted', BookRemoved::class, true, 'eloquent.deleted: '.Book::class],
        ]);
});

test('creating a Nickname through Eloquent, no command, publishes NicknameGiven as a root message with the acting user', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $nickname = Nickname::query()->create(['given_by' => $user->id, 'member_id' => 2, 'nickname' => 'The Quiet One']);

    $publication = EventPublication::query()->sole();
    $event = $publication->message()->payload;

    expect($event)->toEqual(new NicknameGiven($nickname->id, $user->id, 2, 'The Quiet One'))
        ->and(Str::isUlid((string) $publication->message_id))->toBeTrue()
        ->and($publication->correlation_id)->toBe($publication->message_id)
        ->and($publication->causation_id)->toBeNull()
        ->and($publication->actor_id)->toBe((string) $user->id);
});

test('inside band.give_nickname the published NicknameGiven is the command\'s child', function (): void {
    $this->actingAs(User::factory()->member()->create());

    Laravents::withHeader('correlation_id', 'trace-9')->command(new GiveNickname(2, 'The Quiet One'));

    $publication = EventPublication::query()->sole();

    expect($publication->correlation_id)->toBe('trace-9')
        ->and($publication->causation_id)->not->toBeNull()
        ->and($publication->causation_id)->not->toBe($publication->message_id);

    // As the root command, band.give_nickname's message id is the correlation id, which the event names as its cause.
    EventPublication::query()->delete();
    GiveNickname::send(memberId: 2, nickname: 'The Quiet One');
    $root = EventPublication::query()->sole();
    expect($root->causation_id)->toBe($root->correlation_id);
});

test('a rollback of the creating transaction leaves no outbox row and no job', function (): void {
    $this->actingAs(User::factory()->member()->create());

    expect(fn () => DB::transaction(static function (): void {
        Nickname::query()->create(['given_by' => 1, 'member_id' => 2, 'nickname' => 'The Quiet One']);
        expect(EventPublication::query()->count())->toBe(1);

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class);

    expect(Nickname::query()->count())->toBe(0)
        ->and(EventPublication::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('the constructor path fills the event from the attributes, snake_case or as named, with defaults for the rest', function (): void {
    library();

    $book = Book::query()->create(['title' => 'Dune', 'author_id' => 7]);

    expect(Recorder::calls())->toEqual([['shelved', [new BookShelved($book->id, 'Dune', 7), true]]]);
});

test('on: deleted publishes through fromModel(), which wins over the constructor', function (): void {
    library();
    $book = Book::query()->create(['title' => 'Dune', 'author_id' => 7]);
    Recorder::reset();

    $book->delete();

    expect(Recorder::calls())->toBe([['removed', 'removed: Dune']]);
});

test('Laravents::fake() records the model\'s event as published', function (): void {
    library();
    Laravents::fake();

    Book::query()->create(['title' => 'Dune', 'author_id' => 7]);

    Laravents::assertPublished(BookShelved::class, static fn (BookShelved $event): bool => $event->title === 'Dune');
    expect(Recorder::calls())->toBe([]);
});

test('an event that cannot be built from its model fails discovery, naming the parameter', function (): void {
    expect(fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Publishes'))->build([Shelf::class]))
        ->toThrow(DiscoveryException::class, 'publishes [Laravents\Tests\Fixtures\Publishes\Invalid\Unbuildable\ShelfPainted], which cannot be built from it: constructor parameter [$colour] names no attribute the model declares (id, name, created_at, updated_at) and the event has no fromModel()')
        ->and(fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Publishes'))->build([Drawer::class]))
        ->toThrow(DiscoveryException::class, 'on [opened], which is not one of its model events');
});

test('so laravents:cache fails on it, and writes no cache', function (): void {
    config(['laravents.paths' => [dirname(__DIR__, 2).'/Fixtures/Publishes/Invalid/Unbuildable']]);

    expect(Artisan::call('laravents:cache'))->toBe(1)
        ->and(Artisan::output())->toContain('names no attribute the model declares')
        ->and(app(RegistryCache::class)->exists())->toBeFalse();
});

test('the registry cache round trip keeps the publications', function (): void {
    expect(Artisan::call('laravents:cache'))->toBe(0);

    expect(app(RegistryCache::class)->load()->modelPublications())
        ->toEqual(LaraventsServiceProvider::scan(config())->modelPublications())
        ->toHaveCount(2);
});

test('laravents:show lists the publication under Events published, laravents:list does not', function (): void {
    Artisan::call('laravents:show', ['module' => 'Band']);
    expect(preg_replace('/ \.+ | {2,}/', ' ', Artisan::output()))->toContain('Modules\Band\Api\NicknameGiven from Nickname (created)');

    Artisan::call('laravents:show', ['module' => 'Band', '--json' => true]);
    expect(data_get(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), 'events_published.1.from'))
        ->toBe([['model' => Nickname::class, 'hook' => 'created']]);

    Artisan::call('laravents:list');
    expect(Artisan::output())->toContain('Modules\Band\Handlers\BandHandlers@give')
        ->not->toContain(Nickname::class)
        ->not->toContain(Lineup::class);
});
