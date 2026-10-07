<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bulletin;
use App\Models\BulletinItem;
use App\Models\Story;
use App\Models\StoryVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Bulletin rundowns: an ordered, timed running order read on air from the teleprompter.
 */
class BulletinsController extends Controller
{
    /** GET /api/bulletins */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $visible = $user->visiblePlatformIds();

        $bulletins = Bulletin::with(['platform', 'creator'])
            ->withCount('items')
            ->withSum('items', 'duration_seconds')
            ->when($visible !== null, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('platform_id', $visible)
                ->orWhere(fn ($q) => $q->whereNull('platform_id')->where('created_by', $user->id))))
            ->orderByRaw('air_at IS NULL, air_at DESC')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['data' => $bulletins->map(fn ($b) => $this->presentSummary($b))]);
    }

    /** POST /api/bulletins */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBulletin($request, creating: true);
        $user = $request->user();

        if (! empty($data['platformId']) && ! $user->canWriteFor((int) $data['platformId'])) {
            return response()->json(['message' => 'You are not assigned to that platform.'], 403);
        }

        $bulletin = Bulletin::create([
            'title'        => $data['title'],
            'platform_id'  => $data['platformId'] ?? null,
            'format'       => $data['format'],
            'air_at'       => $data['airAt'] ?? null,
            'slot_seconds' => $data['slotSeconds'] ?? 300,
            'created_by'   => $user->id,
        ]);

        return response()->json(['data' => $this->present($bulletin)], 201);
    }

    /** GET /api/bulletins/{bulletin} */
    public function show(Request $request, Bulletin $bulletin): JsonResponse
    {
        if (! $bulletin->isAccessibleBy($request->user())) {
            return response()->json(['message' => 'You are not assigned to this bulletin\'s platform.'], 403);
        }

        return response()->json(['data' => $this->present($bulletin)]);
    }

    /** PUT /api/bulletins/{bulletin} */
    public function update(Request $request, Bulletin $bulletin): JsonResponse
    {
        $user = $request->user();
        if (! $bulletin->isAccessibleBy($user)) {
            return response()->json(['message' => 'You are not assigned to this bulletin\'s platform.'], 403);
        }

        $data = $this->validateBulletin($request, creating: false);
        if (array_key_exists('platformId', $data) && $data['platformId'] && ! $user->canWriteFor((int) $data['platformId'])) {
            return response()->json(['message' => 'You are not assigned to that platform.'], 403);
        }

        $map = ['title' => 'title', 'platformId' => 'platform_id', 'format' => 'format', 'airAt' => 'air_at', 'slotSeconds' => 'slot_seconds', 'status' => 'status'];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $bulletin->{$col} = $data[$in];
            }
        }
        $bulletin->save();

        return response()->json(['data' => $this->present($bulletin)]);
    }

    /** DELETE /api/bulletins/{bulletin} */
    public function destroy(Request $request, Bulletin $bulletin): JsonResponse
    {
        if (! $bulletin->isAccessibleBy($request->user())) {
            return response()->json(['message' => 'You are not assigned to this bulletin\'s platform.'], 403);
        }
        $bulletin->delete();

        return response()->json(['message' => 'Bulletin deleted.']);
    }

    /**
     * POST /api/bulletins/{bulletin}/items
     * Body: { kind: "story", storyId } | { kind: "break", title?, durationSeconds } | { kind: "custom", title, script? }
     * Stories use their approved version for this bulletin's platform and format when there is one.
     */
    public function addItem(Request $request, Bulletin $bulletin): JsonResponse
    {
        $user = $request->user();
        if (! $bulletin->isAccessibleBy($user)) {
            return response()->json(['message' => 'You are not assigned to this bulletin\'s platform.'], 403);
        }

        $data = $request->validate([
            'kind'            => ['required', Rule::in(['story', 'break', 'custom'])],
            'storyId'         => ['required_if:kind,story', 'nullable', 'integer', 'exists:stories,id'],
            'title'           => ['required_if:kind,custom', 'nullable', 'string', 'max:255'],
            'script'          => ['nullable', 'string'],
            'durationSeconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
        ]);

        $item = new BulletinItem(['kind' => $data['kind']]);

        if ($data['kind'] === 'story') {
            $story = Story::with('versions')->findOrFail($data['storyId']);
            if (! $story->isVisibleTo($user) || ! in_array($story->status, ['approved', 'live'], true)) {
                return response()->json(['message' => 'Only approved or live stories you can see can go in a bulletin.'], 422);
            }

            $version = $this->bestVersion($story, $bulletin);
            $item->story_id = $story->id;
            $item->story_version_id = $version?->id;
            $item->title = $version?->headline ?? $story->title;
            $item->script = $version ? $version->spokenText() : $this->storyScript($story, $bulletin->format);
            $item->duration_seconds = BulletinItem::secondsFor($item->script);
        } elseif ($data['kind'] === 'break') {
            $item->title = $data['title'] ?: 'Break';
            $item->script = $data['script'] ?? null;
            $item->duration_seconds = $data['durationSeconds'] ?? 120;
            $item->auto_duration = false;
        } else {
            $item->title = $data['title'];
            $item->script = $data['script'] ?? '';
            $item->duration_seconds = $data['durationSeconds'] ?? BulletinItem::secondsFor($item->script);
            $item->auto_duration = ! isset($data['durationSeconds']);
        }

        $item->position = ($bulletin->items()->max('position') ?? 0) + 1;
        $bulletin->items()->save($item);
        $bulletin->touch();

        return response()->json(['data' => $this->present($bulletin->fresh())], 201);
    }

    /**
     * PUT /api/bulletins/{bulletin}/items/{item}
     * Body: { title?, script?, notes?, durationSeconds? (fixes the duration), autoDuration? (true = time it from the script) }
     */
    public function updateItem(Request $request, Bulletin $bulletin, BulletinItem $item): JsonResponse
    {
        if (! $bulletin->isAccessibleBy($request->user()) || $item->bulletin_id !== $bulletin->id) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $data = $request->validate([
            'title'           => ['sometimes', 'string', 'max:255'],
            'script'          => ['sometimes', 'nullable', 'string'],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'durationSeconds' => ['sometimes', 'integer', 'min:0', 'max:36000'],
            'autoDuration'    => ['sometimes', 'boolean'],
        ]);

        if (isset($data['title'])) $item->title = $data['title'];
        if (array_key_exists('script', $data)) $item->script = $data['script'];
        if (array_key_exists('notes', $data)) $item->notes = $data['notes'];
        if (isset($data['durationSeconds'])) {
            $item->duration_seconds = $data['durationSeconds'];
            $item->auto_duration = false;
        }
        if (! empty($data['autoDuration'])) {
            $item->auto_duration = true;
        }
        if ($item->auto_duration && $item->kind !== 'break') {
            $item->duration_seconds = BulletinItem::secondsFor($item->script);
        }
        $item->save();
        $bulletin->touch();

        return response()->json(['data' => $this->present($bulletin->fresh())]);
    }

    /** DELETE /api/bulletins/{bulletin}/items/{item} */
    public function removeItem(Request $request, Bulletin $bulletin, BulletinItem $item): JsonResponse
    {
        if (! $bulletin->isAccessibleBy($request->user()) || $item->bulletin_id !== $bulletin->id) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }
        $item->delete();
        $this->renumber($bulletin);

        return response()->json(['data' => $this->present($bulletin->fresh())]);
    }

    /** PUT /api/bulletins/{bulletin}/order   Body: { itemIds: [5, 2, 9, …] } */
    public function reorder(Request $request, Bulletin $bulletin): JsonResponse
    {
        if (! $bulletin->isAccessibleBy($request->user())) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }
        $data = $request->validate(['itemIds' => ['required', 'array'], 'itemIds.*' => ['integer']]);

        $ids = $bulletin->items()->pluck('id')->all();
        if (count($data['itemIds']) !== count($ids) || array_diff($data['itemIds'], $ids)) {
            return response()->json(['message' => 'The order must list every item in this bulletin exactly once.'], 422);
        }

        DB::transaction(function () use ($data) {
            foreach ($data['itemIds'] as $i => $id) {
                BulletinItem::whereKey($id)->update(['position' => $i + 1]);
            }
        });
        $bulletin->touch();

        return response()->json(['data' => $this->present($bulletin->fresh())]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateBulletin(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title'       => [$req, 'string', 'max:255'],
            'platformId'  => ['nullable', 'integer', 'exists:platforms,id'],
            'format'      => [$req, Rule::in(['tv', 'radio'])],
            'airAt'       => ['nullable', 'date'],
            'slotSeconds' => ['nullable', 'integer', 'min:30', 'max:36000'],
            'status'      => ['sometimes', Rule::in(['draft', 'ready', 'aired'])],
        ]);
    }

    /** Approved/live version for this platform + format, else any approved/live version of this format */
    private function bestVersion(Story $story, Bulletin $bulletin): ?StoryVersion
    {
        $ok = $story->versions->where('format', $bulletin->format)->whereIn('status', ['approved', 'live']);

        return $ok->firstWhere('platform_id', $bulletin->platform_id) ?? $ok->first();
    }

    /** No broadcast version: build a read from the story itself */
    private function storyScript(Story $story, string $format): string
    {
        $body = StoryVersion::plain($story->content);

        return $format === 'tv' && $story->summary ? $story->summary . "\n\n" . $body : $body;
    }

    private function renumber(Bulletin $bulletin): void
    {
        foreach ($bulletin->items()->get() as $i => $item) {
            if ($item->position !== $i + 1) {
                $item->update(['position' => $i + 1]);
            }
        }
    }

    private function presentSummary(Bulletin $b): array
    {
        return [
            'id'           => $b->id,
            'title'        => $b->title,
            'platformId'   => $b->platform_id,
            'platform'     => $b->platform?->name,
            'format'       => $b->format,
            'airAt'        => $b->air_at?->toIso8601String(),
            'slotSeconds'  => $b->slot_seconds,
            'status'       => $b->status,
            'itemsCount'   => (int) ($b->items_count ?? $b->items()->count()),
            'totalSeconds' => (int) ($b->items_sum_duration_seconds ?? $b->items()->sum('duration_seconds')),
            'createdBy'    => $b->creator?->name,
            'updatedAt'    => $b->updated_at->toIso8601String(),
        ];
    }

    private function present(Bulletin $b): array
    {
        $b->load(['platform', 'creator', 'items.version.platform', 'items.story']);

        return $this->presentSummary($b) + [
            'items' => $b->items->map(fn (BulletinItem $i) => [
                'id'              => $i->id,
                'position'        => $i->position,
                'kind'            => $i->kind,
                'title'           => $i->title,
                'script'          => $i->script,
                'durationSeconds' => $i->duration_seconds,
                'autoDuration'    => $i->auto_duration,
                'notes'           => $i->notes,
                'storyId'         => $i->story_id ? (string) $i->story_id : null,
                'source'          => $i->kind !== 'story' ? null
                    : ($i->version ? "{$i->version->platform?->name} {$i->version->format} version" : 'Main story text'),
            ])->values(),
        ];
    }
}
