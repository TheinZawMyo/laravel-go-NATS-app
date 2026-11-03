<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_reports', function (Blueprint $table) {
            $table->string('action_taken')->nullable()->after('status');
            $table->text('resolution_note')->nullable()->after('action_taken');
            $table->index(['reported_user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_reports', function (Blueprint $table) {
            $table->dropIndex(['reported_user_id', 'status', 'created_at']);
            $table->dropColumn(['action_taken', 'resolution_note']);
        });
    }
};
