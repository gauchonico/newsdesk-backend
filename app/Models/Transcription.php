<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transcription extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'title',
        'audio_filename',
        'audio_path',
        'audio_size',
        'duration',
        'language',
        'language_label',
        'has_timestamps',
        'has_speakers',
        'status',
        'processing_stage',
        'progress_percent',
        'estimated_seconds_left',
        'error_message',
        'assemblyai_transcript_id',
        'word_count',
        'speaker_count',
        'plain_text',
        'segments',
        'proposed_headline',
        'proposed_summary',
        'user_id',
    ];

    protected $casts = [
        'has_timestamps'        => 'boolean',
        'has_speakers'          => 'boolean',
        'segments'              => 'array',
        'progress_percent'      => 'integer',
        'estimated_seconds_left'=> 'integer',
        'word_count'            => 'integer',
        'speaker_count'         => 'integer',
        'audio_size'            => 'integer',
        'duration'              => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function markStage(string $stage, int $percent, ?int $secondsLeft = null): void
    {
        $this->update([
            'processing_stage'       => $stage,
            'progress_percent'       => $percent,
            'estimated_seconds_left' => $secondsLeft,
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status'          => 'failed',
            'processing_stage'=> 'queued',   // reset so UI shows clean error
            'error_message'   => $reason,
            'progress_percent'=> 0,
        ]);
    }

    public function markDone(array $result): void
    {
        $this->update(array_merge($result, [
            'status'                 => 'done',
            'processing_stage'       => 'done',
            'progress_percent'       => 100,
            'estimated_seconds_left' => 0,
        ]));
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}