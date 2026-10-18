<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox: one row per (event, asynchronous listener). A row is written on the connection of the innermost
 * open transaction around the publisher, else on the default connection, so this table must exist on every
 * connection a publisher's transaction uses, #[Transactional(connection: ...)] or a hand-written
 * DB::connection(...)->transaction(): run this migration there too (php artisan migrate --database=<name>)
 * and list the connection in laravents.outbox.connections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laravents_event_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('routing_key');
            $table->string('event_class')->nullable();
            $table->longText('event_payload');
            $table->string('listener');
            $table->json('headers');
            $table->string('message_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('causation_id')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('actor_guard')->nullable();
            $table->timestamp('published_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index(['completed_at', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravents_event_publications');
    }
};
