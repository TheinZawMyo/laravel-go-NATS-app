<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            // Presigned uploads create the row before the message exists, so
            // the message link becomes nullable and is set on send.
            $table->foreignUuid('message_id')->nullable()->change();

            $table->foreignUuid('uploader_id')->nullable()->after('message_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable()->after('size_bytes');
            $table->timestamp('expires_at')->nullable()->after('uploaded_at');
            $table->string('thumbnail_key')->nullable()->after('storage_key');

            $table->index('expires_at');
            $table->index(['uploader_id', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['uploader_id', 'uploaded_at']);
            $table->dropColumn(['uploaded_at', 'expires_at', 'thumbnail_key', 'uploader_id']);

            $table->foreignUuid('message_id')->nullable(false)->change();
        });
    }
};
