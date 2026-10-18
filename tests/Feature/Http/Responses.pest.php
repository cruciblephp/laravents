<?php

declare(strict_types=1);

use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Tests\Fixtures\Http\Desk\Handlers\DeskHandlers;
use Laravents\Tests\Fixtures\Recorder;

beforeEach(function (): void {
    Recorder::reset();
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Http'))->build([DeskHandlers::class]));
});

test('a Responsable result is passed through as it is, status and headers included', function (): void {
    $this->getJson('/laravents/queries/desk.get_receipt?id=9')
        ->assertStatus(202)
        ->assertHeader('X-Receipt', '9')
        ->assertExactJson(['receipt' => 9]);
});

test('a JsonResource result is its own response, not wrapped again', function (): void {
    $this->postJson('/laravents/queries/desk.show_ticket', ['id' => 4])
        ->assertOk()
        ->assertExactJson(['data' => ['id' => 4], 'meta' => ['source' => 'desk']]);
});

test('a null result is a 204 with no body', function (): void {
    $response = $this->postJson('/laravents/commands/desk.archive_ticket', ['id' => 3])->assertNoContent();

    expect($response->getContent())->toBe('')
        ->and(Recorder::calls())->toBe([['archive', 3]]);
});
