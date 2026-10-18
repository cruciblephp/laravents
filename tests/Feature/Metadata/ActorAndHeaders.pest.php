<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\MissingHeaderException;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Metadata\Billing\Handlers\BillingHandlers;
use Laravents\Tests\Fixtures\Metadata\Billing\Listeners\ReceiptListener;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Metadata'))->build([
        BillingHandlers::class,
        ReceiptListener::class,
    ]));
});

test('the actor comes from the default guard, as a string id and the guard name', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);

    $metadata = Laravents::query('billing.inspect');
    $this->assertIsArray($metadata);

    expect($metadata['actor_id'])->toBe((string) $user->id)
        ->and($metadata['actor_guard'])->toBe('web');
});

test('a guest has no actor', function (): void {
    $metadata = Laravents::query('billing.inspect');
    $this->assertIsArray($metadata);

    expect($metadata['actor_id'])->toBeNull()
        ->and($metadata['actor_guard'])->toBeNull();
});

test('an actor already in Context wins over the guard, so a nested or queued message keeps its actor', function (): void {
    $this->actingAs(User::factory()->member()->create());
    Context::add(['actor_id' => '42', 'actor_guard' => 'api']);

    $metadata = Laravents::query('billing.inspect');
    $this->assertIsArray($metadata);

    expect($metadata['actor_id'])->toBe('42')
        ->and($metadata['actor_guard'])->toBe('api');
});

test('#[Header] converts numeric and boolean strings for int, float and bool parameters, and uses defaults and null', function (): void {
    expect(Laravents::query('billing.coerce', [], ['n' => '7', 'flag' => 'true', 'ratio' => '0.5']))->toBe([7, true, 0.5, 'none', null])
        ->and(Laravents::query('billing.coerce', [], ['n' => 8, 'flag' => '0', 'ratio' => 2, 'label' => 'x']))->toBe([8, false, 2.0, 'x', null]);
});

test('#[Header] passes a value it cannot convert as is, so PHP rejects it', function (): void {
    expect(fn () => Laravents::query('billing.coerce', [], ['n' => 'seven', 'flag' => '1', 'ratio' => '1']))
        ->toThrow(TypeError::class, '$n');
});

test('a missing header for a required parameter throws MissingHeaderException', function (): void {
    expect(fn () => Laravents::command('billing.audit'))
        ->toThrow(MissingHeaderException::class, 'Header [tenant_id] is missing for '.BillingHandlers::class.'@audit')
        ->and(Laravents::command('billing.audit', [], ['tenant_id' => 't-1']))->toBe('t-1');
});
