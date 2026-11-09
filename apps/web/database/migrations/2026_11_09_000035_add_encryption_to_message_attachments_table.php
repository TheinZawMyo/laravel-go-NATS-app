<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false)->after('mime_type');
            $table->foreignUuid('conversation_id')->nullable()->after('uploader_id')
                ->constrained()->nullOnDelete();
            $table->unsignedInteger('key_version')->nullable()->after('is_encrypted');
            $table->text('iv')->nullable()->after('key_version');
            $table->text('key_iv')->nullable()->after('iv');
            $table->text('key_ciphertext')->nullable()->after('key_iv');

            $table->index(['conversation_id', 'is_encrypted']);
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'is_encrypted']);
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn(['is_encrypted', 'key_version', 'iv', 'key_iv', 'key_ciphertext']);
        });
    }
};
