<?php

namespace App\Support;

use App\Models\Story;
use App\Models\StoryVersion;
use App\Models\User;
use App\Notifications\StoryActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Works out who should hear about what, and sends the in-app notification.
 */
class StoryNotifier
{
    /** Editors on the story's desk; falls back to admins if the desk has no editor. */
    public static function deskEditors(Story $story): Collection
    {
        return static::editorsFor((int) $story->platform_id);
    }

    /** Editors on a platform's desk; falls back to admins if the desk has no editor. */
    public static function editorsFor(int $platformId): Collection
    {
        $editors = User::where('active', true)
            ->whereHas('roles', fn ($q) => $q->where('role', 'editor'))
            ->whereHas('platforms', fn ($q) => $q->where('platforms.id', $platformId))
            ->get();

        if ($editors->isEmpty()) {
            $editors = User::where('active', true)
                ->whereHas('roles', fn ($q) => $q->where('role', 'admin'))
                ->get();
        }

        return $editors;
    }

    public static function submitted(Story $story, User $actor, bool $resubmitted = false): void
    {
        $verb = $resubmitted ? 'resubmitted' : 'submitted';
        $type = $story->storyType?->name;
        $message = "{$actor->name} {$verb} " . ($type ? strtolower($type) . ' ' : '') . "story for {$story->platform?->name}";

        static::send(static::deskEditors($story), $story, $resubmitted ? 'resubmitted' : 'submitted', $message, $actor);
    }

    public static function reviewed(Story $story, User $editor, string $status, ?string $note): void
    {
        $messages = [
            'approved'          => "{$editor->name} approved your story",
            'live'              => "{$editor->name} published your story live",
            'changes_requested' => "{$editor->name} sent your story back for changes",
            'held'              => "{$editor->name} put your story on hold",
            'rejected'          => "{$editor->name} rejected your story",
        ];
        $message = ($messages[$status] ?? "{$editor->name} updated your story") . ($note ? ": “{$note}”" : '');

        static::send(collect([$story->user])->filter(), $story, $status, $message, $editor);
    }

    public static function commented(Story $story, User $commenter, string $body): void
    {
        // Author + whoever is reviewing it; if the author commented, tell the desk editors
        $recipients = collect([$story->user, $story->claimer]);
        if ($commenter->id === $story->user_id) {
            $recipients = $recipients->merge(static::deskEditors($story));
        }

        $excerpt = mb_strimwidth($body, 0, 120, '…');
        static::send($recipients->filter(), $story, 'commented', "{$commenter->name} commented: “{$excerpt}”", $commenter);
    }

    public static function versionSubmitted(StoryVersion $version, User $actor, bool $resubmitted = false): void
    {
        $verb = $resubmitted ? 'updated' : 'added';
        $message = "{$actor->name} {$verb} a {$version->platform?->name} {$version->format} version for review";

        static::send(static::editorsFor($version->platform_id), $version->story, 'version_submitted', $message, $actor);
    }

    public static function versionReviewed(StoryVersion $version, User $editor): void
    {
        $verbs = ['approved' => 'approved', 'live' => 'published', 'held' => 'put on hold', 'rejected' => 'rejected'];
        $message = "{$editor->name} {$verbs[$version->status]} the {$version->platform?->name} {$version->format} version"
            . ($version->review_note ? ": “{$version->review_note}”" : '');

        static::send(collect([$version->creator, $version->story->user])->filter(), $version->story, 'version_' . $version->status, $message, $editor);
    }

    private static function send(Collection $users, Story $story, string $kind, string $message, User $actor): void
    {
        $users = $users->unique('id')->reject(fn ($u) => $u->id === $actor->id);

        if ($users->isNotEmpty()) {
            Notification::send($users, new StoryActivity($story, $kind, $message, $actor));
        }
    }
}
