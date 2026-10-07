<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Source audio
            $table->string('title');
            $table->string('audio_filename');           // original uploaded filename
            $table->string('audio_path');               // storage path (private disk)
            $table->unsignedBigInteger('audio_size')->nullable();    // bytes
            $table->unsignedInteger('duration')->nullable();         // seconds (filled after transcription)

            // Options chosen at upload
            $table->string('language', 10)->default('auto');
            $table->string('language_label')->default('Auto-detect');
            $table->boolean('has_timestamps')->default(false);
            $table->boolean('has_speakers')->default(false);

            // Job tracking
            $table->enum('status', ['processing', 'done', 'failed'])->default('processing');
            $table->enum('processing_stage', [
                'uploading', 'queued', 'transcribing', 'summarising', 'done'
            ])->default('queued');
            $table->unsignedTinyInteger('progress_percent')->default(0);   // 0-100
            $table->unsignedInteger('estimated_seconds_left')->nullable(); // countdown
            $table->text('error_message')->nullable();

            // AssemblyAI reference
            $table->string('assemblyai_transcript_id')->nullable()->index();

            // Results
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedTinyInteger('speaker_count')->nullable();
            $table->longText('plain_text')->nullable();
            $table->json('segments')->nullable();       // TranscriptSegment[]

            // AI-generated story seeds
            $table->string('proposed_headline', 500)->nullable();
            $table->text('proposed_summary')->nullable();

            // Ownership
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};