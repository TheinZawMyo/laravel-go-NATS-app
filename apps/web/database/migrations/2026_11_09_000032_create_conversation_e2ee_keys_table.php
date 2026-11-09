<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_e2ee_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('key_version');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'key_version']);
        });

        Schema::create('conversation_key_wraps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_key_id')->constrained('conversation_e2ee_keys')->cascadeOnDelete();
            $table->foreignUuid('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('algorithm', 60)->default('ECDH-P256+A256GCM');
            $table->text('ephemeral_public_key');
            $table->text('iv');
            $table->text('ciphertext');
            $table->timestamps();

            $table->unique(['conversation_key_id', 'recipient_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_key_wraps');
        Schema::dropIfExists('conversation_e2ee_keys');
    }
};
