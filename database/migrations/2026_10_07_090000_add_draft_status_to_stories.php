<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drafts: private work-in-progress that hasn't been submitted for review yet.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE stories MODIFY status ENUM('draft','pending','in_review','changes_requested','approved','held','live','rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::table('stories')->where('status', 'draft')->delete();
        DB::statement("ALTER TABLE stories MODIFY status ENUM('pending','in_review','changes_requested','approved','held','live','rejected') NOT NULL DEFAULT 'pending'");
    }
};
