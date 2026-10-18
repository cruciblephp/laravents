<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nicknames', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('given_by');
            $table->unsignedBigInteger('member_id');
            $table->string('nickname', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nicknames');
    }
};
