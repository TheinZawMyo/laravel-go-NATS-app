<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e2ee_key_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('ciphertext');
            $table->text('salt');
            $table->text('iv');
            $table->string('kdf', 40)->default('PBKDF2-SHA256');
            $table->unsignedInteger('iterations')->default(600000);
            $table->string('fingerprint', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2ee_key_backups');
    }
};
