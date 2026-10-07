<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryVersion;
use App\Models\User;
use App\Support\StoryNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform versions of a story ("write once, publish everywhere").
 * Each version is reviewed by the desk of its own platform.
 */
class StoryVersionsController extends Controller
{
    private const ACTIONS = ['approve' => 'approved', 'publish' => 'live', 'hold' => 'held', 'reject' => 'rejected'];

    /** GET /api/stories/{story}/versions */
    public function index(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! $story->isVisibleTo($user)) {
            return response()->json(['message' => 'You are not assigned to this story\'s platforms.'], 403);
        }

        $versions = $story->versions()->with(['platform', 'creator', 'reviewer'])->orderBy('id')->get();

        return response()->json(['data' => $versions->map(fn ($v) => $this->present($v, $user, $story))]);
    }

    /** POST /api/stories/{story}/versions */
    public function store(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, creating: true);

        if (! $this->canAddTo($user, $story, (int) $data['platformId'])) {
            return response()->json(['message' => 'You can only add versions for platforms you work on, to stories you can edit.'], 403);
        }

        try {
            $version = $story->versions()->create([
                'platform_id' => $data['platformId'],
                'format'      => $data['format'],
                'headline'    => $data['headline'],
                'intro'       => $data['intro'] ?? null,
                'body'        => $data['body'],
                // Versions on a draft wait with the draft; desk editors' own versions start approved
                'status'      => $user->canReview((int) $data['platformId']) && $story->status !== 'draft' ? 'approved' : 'pending',
                'created_by'  => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['errors' => ['format' => ['This story already has that version for that platform.']]], 422);
        }

        if ($version->status === 'pending' && $story->status !== 'draft') {
            StoryNotifier::versionSubmitted($version->load(['platform', 'story']), $user);
        }

        return response()->json(['data' => $this->present($version->load(['platform', 'creator', 'reviewer']), $user, $story)], 201);
    }

    /** PUT /api/versions/{version} */
    public function update(Request $request, StoryVersion $version): JsonResponse
    {
        $user = $request->user();
        $story = $version->story;
        if (! $this->canEdit($user, $version, $story)) {
            return response()->json(['message' => 'You can\'t edit this version.'], 403);
        }

        $data = $this->validated($request, creating: false);
        $version->fill(array_filter([
            'headline' => $data['headline'] ?? null,
            'body'     => $data['body'] ?? null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('intro', $data)) {
            $version->intro = $data['intro'];
        }

        // A non-editor changing reviewed copy sends it back to the desk
        $resubmitted = false;
        if ($version->isDirty() && ! $user->canReview($version->platform_id) && $version->status !== 'pending') {
            $version->status = 'pending';
            $version->reviewed_by = null;
            $version->reviewed_at = null;
            $resubmitted = true;
        }
        $version->save();

        if ($resubmitted && $story->status !== 'draft') {
            StoryNotifier::versionSubmitted($version->load(['platform', 'story']), $user, resubmitted: true);
        }

        return response()->json(['data' => $this->present($version->load(['platform', 'creator', 'reviewer']), $user, $story)]);
    }

    /** DELETE /api/versions/{version} */
    public function destroy(Request $request, StoryVersion $version): JsonResponse
    {
        $user = $request->user();
        if (! $this->canEdit($user, $version, $version->story) || ($version->status === 'live' && ! $user->canReview($version->platform_id))) {
            return response()->json(['message' => 'You can\'t delete this version.'], 403);
        }

        $version->delete();

        return response()->json(['message' => 'Version deleted.']);
    }

    /**
     * POST /api/versions/{version}/review
     * Body: { "action": "approve|publish|hold|reject", "note"?: string }
     */
    public function review(Request $request, StoryVersion $version): JsonResponse
    {
        $user = $request->user();
        if (! $user->canReview($version->platform_id)) {
            return response()->json(['message' => 'You are not an editor on this version\'s desk.'], 403);
        }
        if ($version->story->status === 'draft') {
            return response()->json(['message' => 'The story is still a draft.'], 422);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'note'   => [Rule::requiredIf(in_array($request->input('action'), ['hold', 'reject'], true)), 'nullable', 'string', 'max:2000'],
        ], ['note.required' => 'Add a note so the reporter knows why.']);

        $version->status = self::ACTIONS[$data['action']];
        $version->review_note = $data['note'] ?? null;
        $version->reviewed_by = $user->id;
        $version->reviewed_at = now();
        $version->save();

        StoryNotifier::versionReviewed($version->load(['platform', 'story', 'creator']), $user);

        return response()->json(['data' => $this->present($version->load(['platform', 'creator', 'reviewer']), $user, $version->story)]);
    }

    /**
     * GET /api/versions?status=pending
     * Versions waiting on the desks this user reviews (admins: every desk).
     */
    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isEditorial()) {
            return response()->json(['data' => []]);
        }
        $visible = $user->visiblePlatformIds();
        $statuses = array_filter(explode(',', (string) $request->input('status', 'pending')));

        $versions = StoryVersion::with(['platform', 'creator', 'reviewer', 'story.user'])
            ->whereIn('status', $statuses)
            ->when($visible !== null, fn ($q) => $q->whereIn('platform_id', $visible))
            ->whereHas('story', fn ($q) => $q->where('status', '!=', 'draft'))
            ->orderBy('updated_at')
            ->get();

        return response()->json(['data' => $versions->map(fn ($v) => $this->present($v, $user, $v->story) + [
            'storyHeadline' => $v->story->title,
            'storyAuthor'   => $v->story->user?->name,
            'storyStatus'   => $v->story->status,
        ])]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'platformId' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:platforms,id'],
            'format'     => [$creating ? 'required' : 'prohibited', Rule::in(StoryVersion::FORMATS)],
            'headline'   => [$req, 'string', 'max:255'],
            'intro'      => ['nullable', 'string', 'max:5000'],
            'body'       => [$req, 'string'],
        ]);
    }

    /** Story author (or an editor who can edit the story) adding a version for a platform they work on */
    private function canAddTo(User $user, Story $story, int $platformId): bool
    {
        if (! $user->canWriteFor($platformId)) {
            return false;
        }
        if ($story->status === 'draft') {
            return $story->user_id === $user->id;
        }

        return $story->user_id === $user->id
            || $user->canReview((int) $story->platform_id)
            || $user->canReview($platformId);
    }

    private function canEdit(User $user, StoryVersion $version, Story $story): bool
    {
        if ($story->status === 'draft') {
            return $story->user_id === $user->id;
        }

        return $version->created_by === $user->id
            || $story->user_id === $user->id
            || $user->canReview($version->platform_id);
    }

    private function present(StoryVersion $v, User $user, Story $story): array
    {
        return [
            'id'          => $v->id,
            'storyId'     => (string) $v->story_id,
            'platformId'  => $v->platform_id,
            'platform'    => $v->platform?->name,
            'platformColor' => $v->platform?->hsl_color,
            'format'      => $v->format,
            'headline'    => $v->headline,
            'intro'       => $v->intro,
            'body'        => $v->body,
            'status'      => $v->status,
            'reviewNote'  => $v->review_note,
            'words'       => $v->wordCount(),
            'readSeconds' => $v->readSeconds(),
            'createdBy'   => $v->creator?->name,
            'reviewedBy'  => $v->reviewer?->name,
            'reviewedAt'  => $v->reviewed_at?->toIso8601String(),
            'updatedAt'   => $v->updated_at->toIso8601String(),
            'canEdit'     => $this->canEdit($user, $v, $story),
            'canReview'   => $user->canReview($v->platform_id) && $story->status !== 'draft',
        ];
    }
}
