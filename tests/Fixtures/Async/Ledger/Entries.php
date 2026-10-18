<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Async\Ledger;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The data the Ledger fixture writes, in a table each test creates inside its own RefreshDatabase transaction.
 */
final class Entries
{
    public static function createTable(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->integer('amount');
        });
    }

    public static function write(int $amount): void
    {
        DB::table('ledger_entries')->insert(['amount' => $amount]);
    }

    public static function count(): int
    {
        return DB::table('ledger_entries')->count();
    }
}
