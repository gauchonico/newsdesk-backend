<?php

namespace App\Notifications;

use App\Models\Story;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * In-app notification about something that happened to a story
 * (submitted, approved, sent back, commented on, …).
 */
class StoryActivity extends Notification
{
    public function __construct(
        public Story $story,
        public string $kind,      // submitted | resubmitted | approved | live | changes_requested | held | rejected | commented
        public string $message,
        public ?User $actor = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'        => $this->kind,
            'story_id'    => $this->story->id,
            'headline'    => $this->story->title,
            'platform'    => $this->story->platform?->name,
            'message'     => $this->message,
            'actor'       => $this->actor?->name,
        ];
    }
}
