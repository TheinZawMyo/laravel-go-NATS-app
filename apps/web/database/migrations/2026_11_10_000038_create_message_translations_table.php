<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->string('target_language', 8);
            $table->string('source_language', 8)->nullable();
            $table->text('translated_body');
            $table->string('provider', 32);
            $table->timestamps();

            // Translation is computed once per message and language; every
            // viewer reads the cached row instead of paying for another call.
            $table->unique(['message_id', 'target_language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_translations');
    }
};
