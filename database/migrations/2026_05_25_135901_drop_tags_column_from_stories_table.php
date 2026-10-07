<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Check if the old field still exists before dropping it safely
            if (Schema::hasColumn('stories', 'tags')) {
                $table->dropColumn('tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Rollback fallback structure if needed later
            $table->json('tags')->nullable();
        });
    }
};