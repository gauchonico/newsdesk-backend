<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * In-app notification about a planned story (assigned, pitched, approved, declined, cancelled).
 */
class AssignmentActivity extends Notification
{
    public function __construct(
        public Assignment $assignment,
        public string $kind,      // assigned | pitch_submitted | pitch_approved | pitch_declined | assignment_cancelled | assignment_taken
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
            'kind'          => $this->kind,
            'assignment_id' => $this->assignment->id,
            'diary_date'    => $this->assignment->diary_date->toDateString(),
            'story_id'      => $this->assignment->story_id,
            'headline'      => $this->assignment->title,
            'platform'      => $this->assignment->platform?->name,
            'message'       => $this->message,
            'actor'         => $this->actor?->name,
        ];
    }
}
