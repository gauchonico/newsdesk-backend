<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story types carry the review-time target editors work against (e.g. Breaking = 10 min)
        Schema::create('story_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('review_minutes');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('story_types')->insert([
            ['name' => 'Breaking News', 'review_minutes' => 10, 'is_default' => false, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Normal',        'review_minutes' => 60, 'is_default' => true,  'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Full review workflow statuses
        DB::statement("ALTER TABLE stories MODIFY status ENUM('pending','in_review','changes_requested','approved','held','live','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('stories', function (Blueprint $table) {
            $table->foreignId('story_type_id')->nullable()->after('platform_id')->constrained('story_types')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->foreignId('claimed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by');
        });

        // Existing stories: treat creation as submission, and give them the default type
        DB::table('stories')->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at')]);
        DB::table('stories')->whereNull('story_type_id')->update([
            'story_type_id' => DB::table('story_types')->where('is_default', true)->value('id'),
        ]);

        // Snapshot of the story each time its text changes — powers "compare with reporter's version"
        Schema::create('story_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->timestamps();
        });

        // Editor ↔ reporter discussion on a story
        Schema::create('story_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_comments');
        Schema::dropIfExists('story_revisions');

        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by');
            $table->dropConstrainedForeignId('story_type_id');
            $table->dropColumn(['submitted_at', 'claimed_at']);
        });

        DB::table('stories')->whereIn('status', ['in_review', 'changes_requested'])->update(['status' => 'pending']);
        DB::table('stories')->where('status', 'rejected')->update(['status' => 'held']);
        DB::statement("ALTER TABLE stories MODIFY status ENUM('pending','approved','live','held') NOT NULL DEFAULT 'pending'");

        Schema::dropIfExists('story_types');
    }
};
