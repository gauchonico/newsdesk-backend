<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Story extends Model
{
    // Added platform_id, summary, is_community, media_url, media_type
    protected $fillable = [
        'user_id', 
        'platform_id', 
        'story_type_id',
        'title', 
        'summary', 
        'content', 
        'status', 
        'category', 
        'is_community',
        'media_url',
        'media_type',
        'submitted_at',
        'claimed_by',
        'claimed_at',
    ];

    protected $casts = [
        'is_community' => 'boolean',
        'submitted_at' => 'datetime',
        'claimed_at'   => 'datetime',
    ];

    /** Statuses that are waiting on an editor */
    public const QUEUE_STATUSES = ['pending', 'in_review'];

    /** A claim older than this is considered abandoned and can be taken by another editor */
    public const CLAIM_MINUTES = 30;

    /**
     * Optional note for the next timeline event written by the model hooks
     * (e.g. the editor's reason when sending a story back). Not persisted.
     */
    public ?string $timelineNote = null;

    protected static function booted()
    {
        // 1. Log creation event
        static::created(function ($story) {
            $story->timeline()->create([
                'actor_id' => Auth::id() ?? $story->user_id,
                'action' => 'created',
                'note' => $story->status === 'draft' ? 'Draft started.' : 'Story initially drafted.',
            ]);
            // Drafts get their first revision when submitted
            if ($story->status !== 'draft') {
                $story->recordRevision();
            }
        });

        // 2. Auto-log status or edit changes to the Timeline
        static::updated(function ($story) {
            $actorId = Auth::id() ?? $story->user_id;

            // Autosaves on a private draft don't belong in the story's history
            if ($story->status === 'draft' && $story->getOriginal('status') === 'draft') {
                $story->timelineNote = null;
                return;
            }

            if ($story->isDirty('status')) {
                // This maps perfectly to Next.js action types: "approved" | "held" | "live" etc.
                $story->timeline()->create([
                    'actor_id' => $actorId,
                    'action' => $story->status, 
                    'note' => $story->timelineNote ?? "Moved from " . $story->getOriginal('status') . " to " . $story->status,
                ]);
            } elseif ($story->isDirty('content') || $story->isDirty('title')) {
                $story->timeline()->create([
                    'actor_id' => $actorId,
                    'action' => 'edited',
                    'note' => 'Story contents updated.',
                ]);
            }
            $story->timelineNote = null;

            if ($story->wasChanged(['title', 'summary', 'content'])) {
                $story->recordRevision();
            }
        });
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function user(): BelongsTo 
    {
        return $this->belongsTo(User::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function timeline(): HasMany 
    {
        return $this->hasMany(StoryTimelineEvent::class);
    }

    public function storyType(): BelongsTo
    {
        return $this->belongsTo(StoryType::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(StoryRevision::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(StoryComment::class);
    }

    /** The planned assignment this story was written for, if any */
    public function assignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Assignment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StoryVersion::class);
    }

    /**
     * Can this user see the story? Its own platform, or any platform it has a version for.
     * Drafts are private to the author.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->status === 'draft') {
            return $this->user_id === $user->id;
        }
        if ($user->canWriteFor((int) $this->platform_id)) {
            return true;
        }
        $mine = $user->platforms()->pluck('platforms.id');
        return $this->versions()->whereIn('platform_id', $mine)->exists();
    }

    public function recordRevision(): void
    {
        $this->revisions()->create([
            'user_id' => Auth::id() ?? $this->user_id,
            'title'   => $this->title,
            'summary' => $this->summary,
            'content' => $this->content,
        ]);
    }

    /** Is someone else actively reviewing this story right now? */
    public function isClaimedByOther(User $user): bool
    {
        return $this->claimed_by
            && $this->claimed_by !== $user->id
            && $this->claimed_at
            && $this->claimed_at->gt(now()->subMinutes(self::CLAIM_MINUTES));
    }

    /** Minutes the review should take, from the story type (falls back to the default type). */
    public function reviewMinutes(): int
    {
        return $this->storyType?->review_minutes ?? StoryType::defaultType()?->review_minutes ?? 60;
    }
}
