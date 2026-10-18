<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Delegate;
use Laravents\Tests\Fixtures\Async\Ledger\Entries;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\LedgerHandlers;
use Laravents\Tests\Fixtures\Async\Ledger\Handlers\PostedListener;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Recorder::reset();
    Entries::createTable();
    Gate::define('ledger.approve', static fn (Authenticatable $user): bool => true);

    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Async'))->build([
        LedgerHandlers::class,
        PostedListener::class,
    ]));
});

/** @return array<string, mixed> */
function jobContextRecords(): array
{
    $records = [];

    foreach (Recorder::calls() as [$handler, $payload]) {
        $records[$handler] = $payload;
    }

    return $records;
}

test('a plain job dispatched from a handler keeps the ids but not the actor: what it sends names its cause, and #[Authorize] treats it as a guest', function (): void {
    $this->actingAs(User::factory()->member()->create());

    Laravents::command(new Delegate);
    runWorker('ledger');

    $records = jobContextRecords();
    $delegate = $records['delegate'] ?? null;
    $acknowledge = $records['acknowledge'] ?? null;
    $this->assertInstanceOf(Ids::class, $delegate);
    $this->assertInstanceOf(Ids::class, $acknowledge);

    expect($records['job: context'] ?? null)->toBe(['message_id' => $delegate->messageId, 'correlation_id' => $delegate->correlationId])
        ->and($acknowledge->causationId)->toBe($delegate->messageId)
        ->and($acknowledge->correlationId)->toBe($delegate->correlationId)
        ->and(array_key_exists('acknowledge: actor_id', $records) ? $records['acknowledge: actor_id'] : 'not recorded')->toBeNull()
        ->and($records['job: approve'] ?? null)->toBe(AuthenticationException::class)
        ->and($records)->not->toHaveKey('approve');
});
