<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineup_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('out_id');
            $table->unsignedBigInteger('in_id');
            $table->string('instrument');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineup_changes');
    }
};
