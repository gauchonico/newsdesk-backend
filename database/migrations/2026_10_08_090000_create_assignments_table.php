<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planned stories: editor assignments and reporter pitches, laid out by day in the news diary.
     *
     * pitched → (approve) → open | assigned → in_progress → filed
     *         → declined                    cancelled (any time, by an editor)
     */
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('brief')->nullable();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_type_id')->nullable()->constrained('story_types')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pitched', 'open', 'assigned', 'in_progress', 'filed', 'declined', 'cancelled'])->default('open');
            $table->date('diary_date');
            $table->dateTime('due_at')->nullable();
            $table->string('location')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('story_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['diary_date', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
