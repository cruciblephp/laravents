<?php

declare(strict_types=1);

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Metadata\Billing\Handlers\BillingHandlers;
use Laravents\Tests\Fixtures\Metadata\Billing\Listeners\ReceiptListener;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;

test('a log line written inside a handler carries the correlation id', function (): void {
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Metadata'))->build([
        BillingHandlers::class,
        ReceiptListener::class,
    ]));
    config([
        'logging.channels.laravents_test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.default' => 'laravents_test',
    ]);

    $messageId = Laravents::command('billing.charge', ['amount' => 5], ['correlation_id' => 'req-77']);

    $logger = Log::channel('laravents_test');
    $this->assertInstanceOf(Logger::class, $logger);
    $monolog = $logger->getLogger();
    $this->assertInstanceOf(Monolog::class, $monolog);
    $handler = $monolog->getHandlers()[0];
    $this->assertInstanceOf(TestHandler::class, $handler);

    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Receipt sent.')
        ->and($record->extra['correlation_id'] ?? null)->toBe('req-77')
        ->and($record->extra['causation_id'] ?? null)->not->toBeNull()
        ->and($record->extra['causation_id'] ?? null)->not->toBe($messageId)
        ->and($record->extra['message_id'] ?? null)->not->toBeNull();
});
