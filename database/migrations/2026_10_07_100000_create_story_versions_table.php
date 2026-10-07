<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Write once, publish everywhere": one story, adapted per platform and format
     * (a KFM radio reader, an NTV TV script, a Monitor social post…), each approved by that platform's desk.
     */
    public function up(): void
    {
        Schema::create('story_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->enum('format', ['web', 'tv', 'radio', 'social']);
            $table->string('headline');
            $table->text('intro')->nullable();      // TV anchor intro / radio cue
            $table->longText('body');
            $table->enum('status', ['pending', 'approved', 'held', 'live', 'rejected'])->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['story_id', 'platform_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_versions');
    }
};
