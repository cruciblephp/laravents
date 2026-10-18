<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headlines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('nickname_id');
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('printed_by');
            $table->string('correlation_id');
            $table->string('publication_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headlines');
    }
};
