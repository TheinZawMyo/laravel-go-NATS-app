<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            // Sequence the member is allowed to read from. Zero means "the whole
            // history"; a positive value hides everything sent before they
            // (re)joined, which is how history visibility is enforced per member.
            $table->unsignedBigInteger('history_from_seq')->default(0)->after('last_read_seq');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->dropColumn('history_from_seq');
        });
    }
};
