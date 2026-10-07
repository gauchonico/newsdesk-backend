<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bulletin rundowns: an ordered, timed running order for a TV or radio bulletin,
     * read on air from the teleprompter.
     */
    public function up(): void
    {
        Schema::create('bulletins', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('platform_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('format', ['tv', 'radio']);
            $table->dateTime('air_at')->nullable();
            $table->unsignedInteger('slot_seconds')->default(300);
            $table->enum('status', ['draft', 'ready', 'aired'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bulletin_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulletin_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->enum('kind', ['story', 'break', 'custom']);
            $table->foreignId('story_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('story_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('script')->nullable();   // a copy, so presenter edits never change the story
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('auto_duration')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['bulletin_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulletin_items');
        Schema::dropIfExists('bulletins');
    }
};
