<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            // pending -> clean/skipped, or infected/failed when rejected. The
            // column decides whether an attachment may be sent and downloaded.
            $table->string('scan_status', 20)
                ->default('pending')
                ->after('uploaded_at');

            $table->timestamp('scanned_at')
                ->nullable()
                ->after('scan_status');

            $table->index(['scan_status', 'uploaded_at']);
        });

        // Attachments stored before scanning existed were accepted unverified,
        // so mark them sendable (`skipped`) instead of leaving them stuck in
        // `pending`, which would make them undownloadable.
        DB::table('message_attachments')
            ->whereNull('scanned_at')
            ->update([
                'scan_status' => 'skipped',
                'scanned_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropIndex(['scan_status', 'uploaded_at']);
            $table->dropColumn(['scan_status', 'scanned_at']);
        });
    }
};
