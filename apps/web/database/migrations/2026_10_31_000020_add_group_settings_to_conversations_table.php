<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            // Telegram-style history setting: when disabled, a member who joins
            // (or rejoins) a group only sees messages sent after they joined.
            $table->boolean('history_visible_to_new_members')->default(true)->after('next_message_seq');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['description', 'history_visible_to_new_members']);
        });
    }
};
