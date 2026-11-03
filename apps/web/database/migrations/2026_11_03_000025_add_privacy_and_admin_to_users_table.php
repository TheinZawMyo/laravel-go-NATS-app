<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_seen_visibility')->default('everyone');
            $table->string('profile_visibility')->default('everyone');
            $table->string('contact_requests_policy')->default('everyone');
            $table->boolean('is_admin')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
            $table->dropColumn([
                'last_seen_visibility',
                'profile_visibility',
                'contact_requests_policy',
                'is_admin',
            ]);
        });
    }
};
