<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned story: an editor's assignment or a reporter's pitch, on a day in the news diary.
 */
class Assignment extends Model
{
    protected $fillable = [
        'title', 'brief', 'platform_id', 'story_type_id', 'assignee_id', 'created_by',
        'status', 'diary_date', 'due_at', 'location', 'decision_note', 'story_id',
    ];

    protected $casts = ['diary_date' => 'date', 'due_at' => 'datetime'];

    /** Statuses that still need doing */
    public const ACTIVE = ['open', 'assigned', 'in_progress'];

    public function platform(): BelongsTo { return $this->belongsTo(Platform::class); }
    public function storyType(): BelongsTo { return $this->belongsTo(StoryType::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function story(): BelongsTo { return $this->belongsTo(Story::class); }
}
