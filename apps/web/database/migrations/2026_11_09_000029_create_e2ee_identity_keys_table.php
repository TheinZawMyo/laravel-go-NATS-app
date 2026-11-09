<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e2ee_identity_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('public_key');
            $table->string('algorithm', 40)->default('ECDH-P256');
            $table->string('fingerprint', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2ee_identity_keys');
    }
};
