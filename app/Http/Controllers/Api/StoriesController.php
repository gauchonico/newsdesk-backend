<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Models\Story;
use App\Models\StoryType;
use App\Models\Tag;
use App\Support\StoryNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoriesController extends Controller
{
    private const STATUSES = ['draft', 'pending', 'in_review', 'changes_requested', 'approved', 'held', 'live', 'rejected'];

    /** A draft can be saved with any of these empty; they're required on submit */
    private const UNTITLED = 'Untitled draft';

    /**
     * GET /api/stories
     * Filters: ?platform_id=, ?status=pending,in_review, ?mine=1, ?search=, ?drafts=1 (my drafts only)
     * Paging: ?per_page= (max 100) returns { data, meta }; without it, every matching story is returned.
     * Admins see every platform; editors and reporters only their assigned platforms.
     * Drafts are private to their author and only appear with ?drafts=1.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $visible = $user->visiblePlatformIds();
        $statuses = array_filter(explode(',', (string) $request->input('status')));
        $search = trim((string) $request->input('search'));

        $query = Story::with(['user', 'platform', 'tags', 'storyType', 'claimer', 'versions.platform'])
            ->when(
                $request->boolean('drafts'),
                fn ($q) => $q->where('status', 'draft')->where('user_id', $user->id),
                fn ($q) => $q->where('status', '!=', 'draft')
                    ->when($visible !== null, fn ($q) => $q->where(fn ($q) => $q
                        ->whereIn('platform_id', $visible)
                        ->orWhereHas('versions', fn ($v) => $v->whereIn('platform_id', $visible))))
            )
            // A platform's feed includes stories with a version for it
            ->when($request->integer('platform_id'), fn ($q, $platformId) => $q->where(fn ($q) => $q
                ->where('platform_id', $platformId)
                ->orWhereHas('versions', fn ($v) => $v->where('platform_id', $platformId))))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $user->id))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            }))
            ->orderBy('updated_at', 'desc');

        if ($request->filled('per_page')) {
            $page = $query->paginate(min(max($request->integer('per_page'), 1), 100));

            return response()->json([
                'data' => collect($page->items())->map(fn ($story) => $this->present($story)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                    'per_page'     => $page->perPage(),
                    'total'        => $page->total(),
                ],
            ]);
        }

        return response()->json(['data' => $query->get()->map(fn ($story) => $this->present($story))]);
    }

    /**
     * GET /api/stories/{id}
     * A single story with its full timeline.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $story = Story::with(['user', 'platform', 'tags', 'storyType', 'claimer', 'timeline.actor', 'versions.platform', 'assignment.creator'])->find($id);

        if (! $story) {
            return response()->json(['message' => 'Story wire not found.'], 404);
        }
        $user = $request->user();
        if (! $story->isVisibleTo($user)) {
            return response()->json(['message' => $story->status === 'draft' ? 'This draft is private to its author.' : 'You are not assigned to this platform.'], 403);
        }

        return response()->json(['data' => $this->present($story, withTimeline: true)]);
    }

    /**
     * POST /api/stories
     */
    public function store(Request $request): JsonResponse
    {
        $isDraft = $request->input('status') === 'draft';
        $required = $isDraft ? 'nullable' : 'required';

        $validated = $request->validate([
            'headline'    => "{$required}|string|max:255",
            'summary'     => 'nullable|string',
            'content'     => "{$required}|string",
            'platform'    => "{$required}|string", // e.g. "NTV", "KFM"
            'status'      => ['required', 'string', Rule::in(self::STATUSES)],
            'category'    => "{$required}|string|max:100",
            'tags'        => 'nullable|array',
            'isCommunity' => 'nullable|boolean',
            'storyTypeId' => 'nullable|integer|exists:story_types,id',
        ]);

        $user = $request->user();
        $platform = null;
        if (! empty($validated['platform'])) {
            $platform = Platform::where('name', $validated['platform'])->first();
            if (! $platform) {
                return response()->json(['errors' => ['platform' => ['Unknown platform.']]], 422);
            }
            if (! $user->canWriteFor($platform->id)) {
                return response()->json(['message' => 'You are not assigned to this platform.'], 403);
            }
        }

        $status = $isDraft ? 'draft' : ($user->canReview($platform->id) ? $validated['status'] : 'pending');

        $story = Story::create([
            'user_id'       => $user->id,
            'platform_id'   => $platform?->id,
            'story_type_id' => $validated['storyTypeId'] ?? StoryType::defaultType()?->id,
            'title'         => ($validated['headline'] ?? null) ?: self::UNTITLED,
            'summary'       => $validated['summary'] ?? null,
            'content'       => $validated['content'] ?? '',
            // Only editors on this desk can approve or publish; everyone else files for review
            'status'        => $status,
            'submitted_at'  => $isDraft ? null : now(),
            'category'      => $validated['category'] ?? '',
            'is_community'  => $validated['isCommunity'] ?? false,
        ]);

        $this->syncTags($story, $validated['tags'] ?? null);

        if ($story->status === 'pending') {
            StoryNotifier::submitted($story->load(['platform', 'storyType']), $user);
        }

        return response()->json(['data' => ['id' => $story->id]], 201);
    }

    /**
     * PUT /api/stories/{id}
     * Authors edit their own stories; editors edit anything on their desk.
     * When an author edits a story that was sent back, approved or held, it goes back into the review queue.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $story = Story::find($id);
        if (! $story) {
            return response()->json(['message' => 'Story wire not found.'], 404);
        }

        $user = $request->user();
        $isAuthor = $story->user_id === $user->id;
        $isDraft = $story->status === 'draft';
        $canReview = ! $isDraft && $user->canReview((int) $story->platform_id);

        if ($isDraft && ! $isAuthor) {
            return response()->json(['message' => 'This draft is private to its author.'], 403);
        }

        if (! $canReview && ! $isAuthor) {
            return response()->json(['message' => 'You can only edit your own stories.'], 403);
        }
        if (! $canReview && in_array($story->status, ['live', 'rejected'], true)) {
            return response()->json(['message' => 'This story is ' . $story->status . ' and can no longer be edited.'], 403);
        }

        $required = $isDraft ? 'nullable' : 'required';
        $validated = $request->validate([
            'headline'    => "sometimes|{$required}|string|max:255",
            'summary'     => 'nullable|string',
            'content'     => "sometimes|{$required}|string",
            'platform'    => "sometimes|{$required}|string",
            'status'      => ['sometimes', 'required', 'string', Rule::in(self::STATUSES)],
            'category'    => "sometimes|{$required}|string",
            'tags'        => 'nullable|array',
            'isCommunity' => 'nullable|boolean',
            'storyTypeId' => 'nullable|integer|exists:story_types,id',
        ]);

        if (array_key_exists('headline', $validated)) $story->title = $validated['headline'] ?: self::UNTITLED;
        if (array_key_exists('summary', $validated))  $story->summary = $validated['summary'];
        if (array_key_exists('content', $validated))  $story->content = $validated['content'] ?? '';
        if (array_key_exists('category', $validated)) $story->category = $validated['category'] ?? '';
        if (isset($validated['isCommunity'])) $story->is_community = $validated['isCommunity'];
        if (isset($validated['storyTypeId'])) $story->story_type_id = $validated['storyTypeId'];

        // Drafts leave draft status only through POST /submit
        if (! $isDraft && isset($validated['status']) && $validated['status'] !== $story->status) {
            if (! $canReview) {
                return response()->json(['message' => 'Only editors on this desk can change a story\'s status.'], 403);
            }
            $story->status = $validated['status'];
        }

        if (! empty($validated['platform'])) {
            $platform = Platform::where('name', $validated['platform'])->first();
            if (! $platform) {
                return response()->json(['errors' => ['platform' => ['Unknown platform.']]], 422);
            }
            if (! $user->canWriteFor($platform->id)) {
                return response()->json(['message' => 'You are not assigned to this platform.'], 403);
            }
            $story->platform_id = $platform->id;
        }

        // Author revised a story that had already been reviewed → back into the queue
        $resubmitted = false;
        if (! $canReview && in_array($story->status, ['changes_requested', 'approved', 'held'], true) && $story->isDirty()) {
            $story->status = 'pending';
            $story->submitted_at = now();
            $story->claimed_by = null;
            $story->claimed_at = null;
            $story->timelineNote = 'Resubmitted for review after changes.';
            $resubmitted = true;
        }

        $story->save(); // Triggers the timeline + revision hooks
        $this->syncTags($story, $validated['tags'] ?? null);

        if ($resubmitted) {
            StoryNotifier::submitted($story->load(['platform', 'storyType']), $user, resubmitted: true);
        }

        return response()->json(['message' => 'Story updated successfully.']);
    }

    /**
     * POST /api/stories/{id}/submit
     * Sends the author's draft into the review queue. Everything a reviewer needs must be filled in.
     */
    public function submit(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if ($story->user_id !== $user->id || $story->status !== 'draft') {
            return response()->json(['message' => 'Only the author can submit their own draft.'], 403);
        }

        $missing = array_filter([
            'headline' => ($story->title === '' || $story->title === self::UNTITLED) ? 'Add a headline.' : null,
            'content'  => trim(strip_tags($story->content)) === '' ? 'Write the story content.' : null,
            'platform' => ! $story->platform_id ? 'Choose a platform.' : null,
            'category' => $story->category === '' ? 'Choose a category.' : null,
        ]);
        if ($missing) {
            return response()->json([
                'message' => 'This draft isn\'t ready to submit yet.',
                'errors'  => array_map(fn ($m) => [$m], $missing),
            ], 422);
        }
        if (! $user->canWriteFor((int) $story->platform_id)) {
            return response()->json(['message' => 'You are not assigned to this platform.'], 403);
        }

        $story->status = 'pending';
        $story->submitted_at = now();
        $story->timelineNote = 'Submitted for review.';
        $story->save();
        $story->recordRevision(); // the version reviewers compare edits against

        \App\Models\Assignment::where('story_id', $story->id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->update(['status' => 'filed']);

        StoryNotifier::submitted($story->load(['platform', 'storyType']), $user);

        return response()->json(['data' => ['id' => $story->id, 'status' => $story->status]]);
    }

    /**
     * DELETE /api/stories/{id} — authors can discard their own drafts
     */
    public function destroy(Request $request, Story $story): JsonResponse
    {
        if ($story->user_id !== $request->user()->id || $story->status !== 'draft') {
            return response()->json(['message' => 'Only the author can delete their own draft.'], 403);
        }

        // A planned story whose draft is thrown away goes back to "assigned"
        \App\Models\Assignment::where('story_id', $story->id)->update(['story_id' => null, 'status' => 'assigned']);

        $story->tags()->detach();
        $story->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    /**
     * Shape a story for the frontend. Shared by every story endpoint so the fields stay consistent.
     */
    public static function present(Story $story, bool $withTimeline = false): array
    {
        $submittedAt = $story->submitted_at ?? $story->created_at;

        $data = [
            'id'           => (string) $story->id,
            'headline'     => $story->title,
            'summary'      => $story->summary,
            'content'      => $story->content,
            'platform'     => $story->platform?->name ?? '',
            'platformId'   => $story->platform_id,
            'authorId'     => $story->user_id,
            'status'       => $story->status,
            'category'     => $story->category,
            'tags'         => $story->tags ? $story->tags->pluck('name')->toArray() : [],
            'author'       => $story->user ? $story->user->name : 'Anonymous Staff',
            'isCommunity'  => (bool) $story->is_community,
            'mediaType'    => $story->media_type,
            'mediaUrl'     => $story->media_url,
            'versions'     => $story->relationLoaded('versions')
                ? $story->versions->map(fn ($v) => [
                    'id' => $v->id, 'platformId' => $v->platform_id, 'platform' => $v->platform?->name,
                    'format' => $v->format, 'status' => $v->status,
                ])->values()
                : [],
            'storyType'    => $story->storyType ? [
                'id'            => $story->storyType->id,
                'name'          => $story->storyType->name,
                'reviewMinutes' => $story->storyType->review_minutes,
            ] : null,
            'reviewMinutes' => $story->reviewMinutes(),
            'submittedAt'  => $submittedAt?->toIso8601String(),
            'claimedBy'    => $story->claimed_by && $story->claimed_at?->gt(now()->subMinutes(Story::CLAIM_MINUTES))
                ? ['id' => $story->claimed_by, 'name' => $story->claimer?->name]
                : null,
            'createdAt'    => ($story->created_at ?? now())->toIso8601String(),
            'updatedAt'    => ($story->updated_at ?? now())->toIso8601String(),
        ];

        if ($story->relationLoaded('assignment') && $story->assignment) {
            $a = $story->assignment;
            $data['assignment'] = [
                'id'         => $a->id,
                'brief'      => $a->brief,
                'dueAt'      => $a->due_at?->toIso8601String(),
                'location'   => $a->location,
                'assignedBy' => $a->creator?->name,
                'diaryDate'  => $a->diary_date->toDateString(),
            ];
        }

        if ($withTimeline) {
            $data['timeline'] = $story->timeline->sortBy('id')->values()->map(fn ($event) => [
                'action' => $event->action,
                'by'     => $event->actor ? $event->actor->name : 'System Desk',
                'at'     => $event->created_at->toIso8601String(),
                'note'   => $event->note,
            ])->toArray();
        }

        return $data;
    }

    private function syncTags(Story $story, ?array $tags): void
    {
        if ($tags === null) {
            return;
        }

        $tagIds = [];
        foreach ($tags as $tagName) {
            if (empty(trim($tagName))) continue;
            $tagIds[] = Tag::firstOrCreate(['name' => trim($tagName)])->id;
        }
        $story->tags()->sync($tagIds);
    }
}
