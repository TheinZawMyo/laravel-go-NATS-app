<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL full-text search over message bodies. SQLite (the test
     * database) has no tsvector type, so the search service falls back to a
     * LIKE query there and this migration is a no-op.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (to_tsvector('simple', coalesce(body, ''))) STORED
        SQL);

        DB::statement('CREATE INDEX messages_search_vector_index ON messages USING GIN (search_vector)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS messages_search_vector_index');
        DB::statement('ALTER TABLE messages DROP COLUMN IF EXISTS search_vector');
    }
};
