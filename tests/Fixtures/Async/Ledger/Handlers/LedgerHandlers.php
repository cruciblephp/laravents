<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger\Handlers;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Header;
use Laravents\Attributes\Transactional;
use Laravents\Bus\ContextMetadata;
use Laravents\Contracts\CommandBus;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Acknowledge;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Approve;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Book;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Delegate;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Post;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Posted;
use Laravents\Tests\Fixtures\Async\Ledger\Api\Reject;
use Laravents\Tests\Fixtures\Async\Ledger\Entries;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;
use RuntimeException;
use Throwable;

final class LedgerHandlers
{
    /** The outer transaction: looks at the jobs table after the inner command has committed its own. */
    #[CommandHandler]
    #[Transactional]
    public function book(Book $command, CommandBus $commands): void
    {
        $commands->dispatch(new Post($command->amount));

        Recorder::record('book: transaction level', DB::transactionLevel());
        Recorder::record('book: jobs while open', DB::table('jobs')->count());

        if ($command->fail) {
            throw new RuntimeException('Booking failed.');
        }
    }

    #[CommandHandler]
    #[Transactional]
    public function post(
        Post $command,
        EventBus $events,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
    ): void {
        Recorder::record('post', Ids::capture($messageId, $correlationId, $causationId));
        Entries::write($command->amount);
        $events->publish(new Posted($command->amount));
    }

    #[CommandHandler]
    public function acknowledge(
        Acknowledge $command,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
        #[Header('actor_id')] ?string $actorId,
    ): void {
        Recorder::record('acknowledge', Ids::capture($messageId, $correlationId, $causationId));
        Recorder::record('acknowledge: actor_id', $actorId);
    }

    /** Dispatches a plain closure job that reports the Context it was given and sends two commands. */
    #[CommandHandler]
    public function delegate(
        Delegate $command,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
    ): void {
        Recorder::record('delegate', Ids::capture($messageId, $correlationId, null));

        dispatch(static function (): void {
            Recorder::record('job: context', Context::only(ContextMetadata::KEYS));
            app(CommandBus::class)->dispatch(new Acknowledge(1));

            try {
                app(CommandBus::class)->dispatch(new Approve);
            } catch (Throwable $e) {
                Recorder::record('job: approve', $e::class);
            }
        })->onQueue('ledger');
    }

    #[CommandHandler]
    #[Authorize]
    public function approve(Approve $command): void
    {
        Recorder::record('approve');
    }

    #[CommandHandler]
    public function reject(Reject $command): never
    {
        throw new RuntimeException('Rejected.');
    }
}
