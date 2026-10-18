<?php

declare(strict_types=1);

namespace Laravents\Outbox;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;

/**
 * The connection an outbox row is written on: that of the innermost open database transaction, else null for
 * the default. Laravel's DatabaseTransactionsManager (db.transactions) keeps every transaction opened through a
 * Connection, on every connection, in the order they began: #[Transactional] and a hand-written
 * DB::connection(...)->transaction() alike. It is the record DB::afterCommit() and ShouldQueueAfterCommit read.
 * A transaction opened with raw SQL (DB::unprepared('BEGIN')) or on a PDO the manager does not see is invisible
 * to it, as it is to afterCommit(): the row then goes on the default connection.
 */
final readonly class PublisherConnection
{
    public function __construct(private DatabaseTransactionsManager $transactions) {}

    public function name(): ?string
    {
        $innermost = $this->transactions->getPendingTransactions()->last();

        return $innermost instanceof DatabaseTransactionRecord ? $innermost->connection : null;
    }
}
