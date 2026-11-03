<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_message_id');
            $table->text('body');
            $table->timestamp('send_at');
            $table->string('status')->default('pending');
            $table->foreignUuid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['sender_id', 'status', 'send_at']);
            $table->unique(['sender_id', 'client_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
