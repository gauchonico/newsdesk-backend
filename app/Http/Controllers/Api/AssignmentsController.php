<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Platform;
use App\Models\Story;
use App\Models\StoryType;
use App\Models\User;
use App\Notifications\AssignmentActivity;
use App\Support\StoryNotifier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Assignments and pitches — the planned stories in the daily news diary.
 */
class AssignmentsController extends Controller
{
    /**
     * GET /api/assignments
     * ?date=YYYY-MM-DD or ?from=&to=, ?status=pitched,open, ?platform_id=, ?mine=1 (assigned to me or pitched by me)
     * Desk members see their desks' plans; pitches are only visible to desk editors and the person who pitched.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $visible = $user->visiblePlatformIds();
        $reviewable = $user->isAdmin() ? null : ($user->isEditorial() ? $visible : []);
        $statuses = array_filter(explode(',', (string) $request->input('status')));

        $from = $request->input('date') ?? $request->input('from');
        $to = $request->input('date') ?? $request->input('to');

        $assignments = Assignment::with(['platform', 'storyType', 'assignee', 'creator', 'story'])
            ->when($visible !== null, fn ($q) => $q->whereIn('platform_id', $visible))
            // Pitches and declined pitches: only the pitcher and desk editors
            ->where(fn ($q) => $q
                ->whereNotIn('status', ['pitched', 'declined'])
                ->orWhere('created_by', $user->id)
                ->when($reviewable === null, fn ($q) => $q->orWhereRaw('1 = 1'))
                ->when($reviewable, fn ($q) => $q->orWhereIn('platform_id', $reviewable)))
            ->when($from, fn ($q) => $q->whereDate('diary_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('diary_date', '<=', $to))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->integer('platform_id'), fn ($q, $id) => $q->where('platform_id', $id))
            ->when($request->boolean('mine'), fn ($q) => $q->where(fn ($q) => $q
                ->where('assignee_id', $user->id)
                ->orWhere(fn ($q) => $q->where('created_by', $user->id)->whereIn('status', ['pitched', 'declined']))))
            ->orderBy('diary_date')
            ->orderByRaw('due_at IS NULL, due_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $assignments->map(fn ($a) => $this->present($a, $user))]);
    }

    /**
     * POST /api/assignments
     * Editors on the desk create assignments; anyone else (or anyone with pitch=true) files a pitch.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'brief'       => ['nullable', 'string', 'max:5000'],
            'platformId'  => ['required', 'integer', 'exists:platforms,id'],
            'storyTypeId' => ['nullable', 'integer', 'exists:story_types,id'],
            'assigneeId'  => ['nullable', 'integer', 'exists:users,id'],
            'diaryDate'   => ['required', 'date'],
            'dueAt'       => ['nullable', 'date'],
            'location'    => ['nullable', 'string', 'max:255'],
            'pitch'       => ['sometimes', 'boolean'],
        ]);
        $platformId = (int) $data['platformId'];

        if (! $user->canWriteFor($platformId)) {
            return response()->json(['message' => 'You are not assigned to that platform.'], 403);
        }

        $isPitch = ! $user->canReview($platformId) || ($data['pitch'] ?? false);
        $assignee = null;
        if (! $isPitch && ! empty($data['assigneeId'])) {
            $assignee = User::find($data['assigneeId']);
            if (! $assignee->canWriteFor($platformId)) {
                return response()->json(['errors' => ['assigneeId' => ["{$assignee->name} isn't on that desk."]]], 422);
            }
        }

        $assignment = Assignment::create([
            'title'         => $data['title'],
            'brief'         => $data['brief'] ?? null,
            'platform_id'   => $platformId,
            'story_type_id' => $data['storyTypeId'] ?? StoryType::defaultType()?->id,
            'assignee_id'   => $assignee?->id,
            'created_by'    => $user->id,
            'status'        => $isPitch ? 'pitched' : ($assignee ? 'assigned' : 'open'),
            'diary_date'    => $data['diaryDate'],
            'due_at'        => $data['dueAt'] ?? null,
            'location'      => $data['location'] ?? null,
        ]);
        $assignment->load('platform');

        if ($isPitch) {
            $this->notify(StoryNotifier::editorsFor($platformId), $assignment, 'pitch_submitted', "{$user->name} pitched a story for {$assignment->platform->name}", $user);
        } elseif ($assignee) {
            $this->notify(collect([$assignee]), $assignment, 'assigned', "{$user->name} assigned you a story for " . $this->day($assignment), $user);
        }

        return response()->json(['data' => $this->present($assignment->fresh(['platform', 'storyType', 'assignee', 'creator', 'story']), $user)], 201);
    }

    /** PUT /api/assignments/{assignment} — desk editors edit anything; pitchers can edit their pitch until it's decided */
    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        $isEditor = $user->canReview($assignment->platform_id);
        $isOwnPitch = $assignment->status === 'pitched' && $assignment->created_by === $user->id;
        if (! $isEditor && ! $isOwnPitch) {
            return response()->json(['message' => 'Only desk editors can change this.'], 403);
        }

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'brief'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'storyTypeId' => ['sometimes', 'nullable', 'integer', 'exists:story_types,id'],
            'assigneeId'  => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'diaryDate'   => ['sometimes', 'date'],
            'dueAt'       => ['sometimes', 'nullable', 'date'],
            'location'    => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        foreach (['title' => 'title', 'brief' => 'brief', 'storyTypeId' => 'story_type_id', 'diaryDate' => 'diary_date', 'dueAt' => 'due_at', 'location' => 'location'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $assignment->{$col} = $data[$in];
            }
        }

        // Reassigning (editors only, and not once a pitch is still undecided)
        $newAssignee = null;
        if ($isEditor && array_key_exists('assigneeId', $data) && $assignment->status !== 'pitched' && $data['assigneeId'] !== $assignment->assignee_id) {
            if ($data['assigneeId']) {
                $newAssignee = User::find($data['assigneeId']);
                if (! $newAssignee->canWriteFor($assignment->platform_id)) {
                    return response()->json(['errors' => ['assigneeId' => ["{$newAssignee->name} isn't on that desk."]]], 422);
                }
            }
            $assignment->assignee_id = $newAssignee?->id;
            if ($assignment->status === 'open' && $newAssignee) $assignment->status = 'assigned';
            if ($assignment->status === 'assigned' && ! $newAssignee) $assignment->status = 'open';
        }
        $assignment->save();
        $assignment->load('platform');

        if ($newAssignee && $newAssignee->id !== $user->id) {
            $this->notify(collect([$newAssignee]), $assignment, 'assigned', "{$user->name} assigned you a story for " . $this->day($assignment), $user);
        }

        return response()->json(['data' => $this->present($assignment->fresh(['platform', 'storyType', 'assignee', 'creator', 'story']), $user)]);
    }

    /**
     * POST /api/assignments/{assignment}/decide
     * Body: { action: "approve", assigneeId? (defaults to the pitcher; null = leave open) } | { action: "decline", note }
     */
    public function decide(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        if (! $user->canReview($assignment->platform_id)) {
            return response()->json(['message' => 'Only desk editors can decide on pitches.'], 403);
        }
        if ($assignment->status !== 'pitched') {
            return response()->json(['message' => 'This pitch has already been decided.'], 422);
        }

        $data = $request->validate([
            'action'     => ['required', Rule::in(['approve', 'decline'])],
            'note'       => ['required_if:action,decline', 'nullable', 'string', 'max:2000'],
            'assigneeId' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ], ['note.required_if' => 'Add a note so the reporter knows why.']);

        $assignment->decision_note = $data['note'] ?? null;
        $pitcher = $assignment->creator;

        if ($data['action'] === 'decline') {
            $assignment->status = 'declined';
            $assignment->save();
            $this->notify(collect([$pitcher]), $assignment->load('platform'), 'pitch_declined', "{$user->name} declined your pitch: “{$assignment->decision_note}”", $user);
        } else {
            $assigneeId = array_key_exists('assigneeId', $data) ? $data['assigneeId'] : $pitcher?->id;
            $assignee = $assigneeId ? User::find($assigneeId) : null;
            if ($assignee && ! $assignee->canWriteFor($assignment->platform_id)) {
                return response()->json(['errors' => ['assigneeId' => ["{$assignee->name} isn't on that desk."]]], 422);
            }
            $assignment->assignee_id = $assignee?->id;
            $assignment->status = $assignee ? 'assigned' : 'open';
            $assignment->save();
            $assignment->load('platform');

            $this->notify(collect([$pitcher]), $assignment, 'pitch_approved', "{$user->name} approved your pitch" . ($assignee && $assignee->id === $pitcher?->id ? ' — it\'s yours' : ''), $user);
            if ($assignee && $assignee->id !== $pitcher?->id) {
                $this->notify(collect([$assignee]), $assignment, 'assigned', "{$user->name} assigned you a story for " . $this->day($assignment), $user);
            }
        }

        return response()->json(['data' => $this->present($assignment->fresh(['platform', 'storyType', 'assignee', 'creator', 'story']), $user)]);
    }

    /** POST /api/assignments/{assignment}/take — claim an open assignment on your desk */
    public function take(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        if ($assignment->status !== 'open' || ! $user->canWriteFor($assignment->platform_id)) {
            return response()->json(['message' => 'This assignment isn\'t open to take.'], 422);
        }

        $assignment->update(['assignee_id' => $user->id, 'status' => 'assigned']);
        $assignment->load('platform');
        $this->notify(collect([$assignment->creator]), $assignment, 'assignment_taken', "{$user->name} took “{$assignment->title}”", $user);

        return response()->json(['data' => $this->present($assignment->fresh(['platform', 'storyType', 'assignee', 'creator', 'story']), $user)]);
    }

    /**
     * POST /api/assignments/{assignment}/start
     * The assignee starts writing: creates a linked draft (or returns the existing one).
     */
    public function start(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        if ($assignment->assignee_id !== $user->id) {
            return response()->json(['message' => 'Only the assigned reporter can start this story.'], 403);
        }
        if ($assignment->story_id) {
            return response()->json(['data' => ['storyId' => (string) $assignment->story_id]]);
        }
        if ($assignment->status !== 'assigned') {
            return response()->json(['message' => 'This assignment can\'t be started.'], 422);
        }

        $story = Story::create([
            'user_id'       => $user->id,
            'platform_id'   => $assignment->platform_id,
            'story_type_id' => $assignment->story_type_id,
            'title'         => $assignment->title,
            'content'       => '',
            'category'      => '',
            'status'        => 'draft',
        ]);
        $assignment->update(['story_id' => $story->id, 'status' => 'in_progress']);

        return response()->json(['data' => ['storyId' => (string) $story->id]], 201);
    }

    /** POST /api/assignments/{assignment}/cancel */
    public function cancel(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        if (! $user->canReview($assignment->platform_id)) {
            return response()->json(['message' => 'Only desk editors can cancel assignments.'], 403);
        }
        if (in_array($assignment->status, ['filed', 'cancelled', 'declined'], true)) {
            return response()->json(['message' => 'This assignment is already closed.'], 422);
        }

        $assignment->update(['status' => 'cancelled']);
        $assignment->load('platform');
        if ($assignment->assignee && $assignment->assignee_id !== $user->id) {
            $this->notify(collect([$assignment->assignee]), $assignment, 'assignment_cancelled', "{$user->name} cancelled “{$assignment->title}”", $user);
        }

        return response()->json(['data' => $this->present($assignment->fresh(['platform', 'storyType', 'assignee', 'creator', 'story']), $user)]);
    }

    /**
     * GET /api/platforms/{platform}/team?date=
     * People on a desk (for the assignee picker), with how many active assignments each has that day.
     */
    public function team(Request $request, Platform $platform): JsonResponse
    {
        if (! $request->user()->canWriteFor($platform->id)) {
            return response()->json(['message' => 'You are not assigned to that platform.'], 403);
        }
        $date = $request->input('date');

        $users = User::with('roles')
            ->where('active', true)
            ->whereHas('platforms', fn ($q) => $q->where('platforms.id', $platform->id))
            ->whereHas('roles', fn ($q) => $q->whereIn('role', ['reporter', 'editor']))
            ->withCount(['assignedStories as load' => fn ($q) => $q
                ->whereIn('status', Assignment::ACTIVE)
                ->when($date, fn ($q) => $q->whereDate('diary_date', $date))])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users->map(fn ($u) => [
            'id'    => $u->id,
            'name'  => $u->name,
            'roles' => $u->roles->pluck('role')->values(),
            'load'  => (int) $u->load,
        ])]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function notify(Collection $users, Assignment $assignment, string $kind, string $message, User $actor): void
    {
        $users = $users->filter()->unique('id')->reject(fn ($u) => $u->id === $actor->id);
        if ($users->isNotEmpty()) {
            Notification::send($users, new AssignmentActivity($assignment, $kind, $message, $actor));
        }
    }

    private function day(Assignment $a): string
    {
        $d = Carbon::parse($a->diary_date);
        return $d->isToday() ? 'today' : ($d->isTomorrow() ? 'tomorrow' : $d->format('D j M'));
    }

    private function present(Assignment $a, User $user): array
    {
        $isEditor = $user->canReview($a->platform_id);
        $isAssignee = $a->assignee_id === $user->id;

        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'brief'        => $a->brief,
            'platformId'   => $a->platform_id,
            'platform'     => $a->platform?->name,
            'platformColor' => $a->platform?->hsl_color,
            'storyType'    => $a->storyType ? ['id' => $a->storyType->id, 'name' => $a->storyType->name] : null,
            'assignee'     => $a->assignee ? ['id' => $a->assignee->id, 'name' => $a->assignee->name] : null,
            'createdBy'    => $a->creator ? ['id' => $a->creator->id, 'name' => $a->creator->name] : null,
            'status'       => $a->status,
            'diaryDate'    => $a->diary_date->toDateString(),
            'dueAt'        => $a->due_at?->toIso8601String(),
            'location'     => $a->location,
            'decisionNote' => $a->decision_note,
            'story'        => $a->story ? ['id' => (string) $a->story->id, 'status' => $a->story->status, 'headline' => $a->story->title] : null,
            'can' => [
                'edit'   => $isEditor || ($a->status === 'pitched' && $a->created_by === $user->id),
                'decide' => $isEditor && $a->status === 'pitched',
                'take'   => $a->status === 'open' && $user->canWriteFor($a->platform_id),
                'start'  => $isAssignee && in_array($a->status, ['assigned', 'in_progress'], true),
                'cancel' => $isEditor && ! in_array($a->status, ['filed', 'cancelled', 'declined'], true),
            ],
        ];
    }
}
