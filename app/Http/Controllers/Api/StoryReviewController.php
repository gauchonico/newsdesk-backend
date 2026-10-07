<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Support\StoryNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Editor review workflow: claim a story, decide on it, compare revisions, discuss with the reporter.
 */
class StoryReviewController extends Controller
{
    /** Review action → resulting status */
    private const ACTIONS = [
        'approve'   => 'approved',
        'publish'   => 'live',
        'send_back' => 'changes_requested',
        'hold'      => 'held',
        'reject'    => 'rejected',
    ];

    /** Actions that need a note so the reporter knows why */
    private const NOTE_REQUIRED = ['send_back', 'hold', 'reject'];

    /**
     * POST /api/stories/{story}/claim   Body: { "force"?: bool }
     * Marks the story as being reviewed by the current editor so two editors don't work on it at once.
     */
    public function claim(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! $user->canReview((int) $story->platform_id)) {
            return response()->json(['message' => 'You are not an editor on this desk.'], 403);
        }

        // Only stories waiting in the queue get claimed; others can be opened freely
        if (! in_array($story->status, Story::QUEUE_STATUSES, true)) {
            return $this->presented($story);
        }

        if ($story->isClaimedByOther($user) && ! $request->boolean('force')) {
            return response()->json([
                'message'   => "{$story->claimer?->name} is reviewing this story.",
                'claimedBy' => ['id' => $story->claimed_by, 'name' => $story->claimer?->name],
            ], 409);
        }

        $story->claimed_by = $user->id;
        $story->claimed_at = now();
        if ($story->status === 'pending') {
            $story->status = 'in_review';
            $story->timelineNote = "{$user->name} started reviewing.";
        }
        $story->save();

        return $this->presented($story);
    }

    /**
     * DELETE /api/stories/{story}/claim
     * Editor leaves without deciding — the story goes back to the queue.
     */
    public function release(Request $request, Story $story): JsonResponse
    {
        if ($story->claimed_by === $request->user()->id) {
            $story->claimed_by = null;
            $story->claimed_at = null;
            if ($story->status === 'in_review') {
                $story->status = 'pending';
                $story->timelineNote = 'Returned to the queue without a decision.';
            }
            $story->save();
        }

        return $this->presented($story);
    }

    /**
     * POST /api/stories/{story}/review
     * Body: { "action": "approve|publish|send_back|hold|reject", "note"?: string }
     */
    public function review(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! $user->canReview((int) $story->platform_id)) {
            return response()->json(['message' => 'You are not an editor on this desk.'], 403);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'note'   => [Rule::requiredIf(in_array($request->input('action'), self::NOTE_REQUIRED, true)), 'nullable', 'string', 'max:2000'],
        ], [
            'note.required' => 'Add a note so the reporter knows why.',
        ]);

        if ($story->isClaimedByOther($user)) {
            return response()->json(['message' => "{$story->claimer?->name} is reviewing this story."], 409);
        }

        $status = self::ACTIONS[$data['action']];
        $note = isset($data['note']) ? trim($data['note']) : null;

        $story->status = $status;
        $story->claimed_by = null;
        $story->claimed_at = null;
        $story->timelineNote = $note ?: null;
        $story->save();

        StoryNotifier::reviewed($story->load(['user', 'platform']), $user, $status, $note ?: null);

        return $this->presented($story);
    }

    /**
     * GET /api/stories/{story}/revisions — oldest first
     */
    public function revisions(Request $request, Story $story): JsonResponse
    {
        if (! $story->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You are not assigned to this platform.'], 403);
        }

        $revisions = $story->revisions()->with('user')->orderBy('id')->get()->map(fn ($r) => [
            'id'       => $r->id,
            'userId'   => $r->user_id,
            'by'       => $r->user?->name ?? 'Unknown',
            'at'       => $r->created_at->toIso8601String(),
            'headline' => $r->title,
            'summary'  => $r->summary,
            'content'  => $r->content,
        ]);

        return response()->json(['data' => $revisions]);
    }

    /**
     * GET /api/stories/{story}/comments — oldest first
     */
    public function comments(Request $request, Story $story): JsonResponse
    {
        if (! $story->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You are not assigned to this platform.'], 403);
        }

        return response()->json([
            'data' => $story->comments()->with('user')->orderBy('id')->get()->map(fn ($c) => $this->presentComment($c)),
        ]);
    }

    /**
     * POST /api/stories/{story}/comments   Body: { "body": string }
     */
    public function addComment(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! $story->isVisibleTo($user)) {
            return response()->json(['message' => 'You are not assigned to this platform.'], 403);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment = $story->comments()->create(['user_id' => $user->id, 'body' => trim($data['body'])]);

        StoryNotifier::commented($story->load(['user', 'claimer', 'platform']), $user, $comment->body);

        return response()->json(['data' => $this->presentComment($comment->load('user'))], 201);
    }

    private function presentComment($comment): array
    {
        return [
            'id'     => $comment->id,
            'userId' => $comment->user_id,
            'by'     => $comment->user?->name ?? 'Unknown',
            'body'   => $comment->body,
            'at'     => $comment->created_at->toIso8601String(),
        ];
    }

    private function presented(Story $story): JsonResponse
    {
        $story->load(['user', 'platform', 'tags', 'storyType', 'claimer', 'timeline.actor']);

        return response()->json(['data' => StoriesController::present($story, withTimeline: true)]);
    }
}
