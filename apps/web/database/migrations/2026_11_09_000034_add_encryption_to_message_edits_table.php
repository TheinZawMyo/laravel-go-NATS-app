<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_edits', function (Blueprint $table) {
            $table->json('previous_encryption')->nullable()->after('previous_body');
            $table->json('current_encryption')->nullable()->after('current_body');
        });
    }

    public function down(): void
    {
        Schema::table('message_edits', function (Blueprint $table) {
            $table->dropColumn(['previous_encryption', 'current_encryption']);
        });
    }
};
