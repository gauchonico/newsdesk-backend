<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Newsroom review performance, computed from each story's timeline.
 * Admins see every platform (optionally filtered); editors see their desks.
 */
class ReviewAnalyticsController extends Controller
{
    /** Timeline actions that count as an editor's decision */
    private const DECISIONS = ['approved', 'live', 'changes_requested', 'held', 'rejected'];

    /**
     * GET /api/analytics/review?days=30&platform_id=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days'        => ['nullable', 'integer', 'between:1,365'],
            'platform_id' => ['nullable', 'integer'],
        ]);
        $days = $data['days'] ?? 30;
        $since = now()->subDays($days)->startOfDay();
        $visible = $request->user()->visiblePlatformIds();

        $scope = fn ($q) => $q
            ->when($visible !== null, fn ($q) => $q->whereIn('platform_id', $visible))
            ->when($data['platform_id'] ?? null, fn ($q, $id) => $q->where('platform_id', $id));

        $stories = Story::with(['user', 'platform', 'storyType', 'timeline.actor'])
            ->where('created_at', '>=', $since)
            ->where('status', '!=', 'draft')
            ->tap($scope)
            ->get();

        $rows = $stories->map(fn (Story $s) => $this->measure($s));
        $reviewed = $rows->whereNotNull('firstReviewMinutes');

        return response()->json(['data' => [
            'days'    => $days,
            'summary' => [
                'submitted'              => $rows->count(),
                'reviewed'               => $reviewed->count(),
                'approved'               => $rows->whereNotNull('approvedAt')->count(),
                'live'                   => $rows->whereNotNull('liveAt')->count(),
                'rejected'               => $rows->where('status', 'rejected')->count(),
                'sentBack'               => $rows->where('sendBacks', '>', 0)->count(),
                'medianFirstReviewMinutes' => $this->median($reviewed->pluck('firstReviewMinutes')),
                'medianSubmitToApproveMinutes' => $this->median($rows->pluck('submitToApproveMinutes')->filter(fn ($v) => $v !== null)),
                'medianApproveToLiveMinutes'   => $this->median($rows->pluck('approveToLiveMinutes')->filter(fn ($v) => $v !== null)),
                'onTargetRate'           => $this->rate($reviewed->where('onTarget', true)->count(), $reviewed->count()),
                'avgRevisionRounds'      => $reviewed->count() ? round($reviewed->avg('sendBacks'), 2) : null,
            ],
            'queue'      => $this->queueNow($scope),
            'daily'      => $this->daily($rows, $since, $days),
            'byPlatform' => $this->byPlatform($rows),
            'byEditor'   => $this->byEditor($stories, $since),
            'byReporter' => $this->byReporter($rows),
        ]]);
    }

    /** Per-story timings derived from the timeline. */
    private function measure(Story $story): array
    {
        $submitted = $story->created_at;
        $events = $story->timeline->sortBy('id');
        $decisions = $events->whereIn('action', self::DECISIONS);

        $firstDecision = $decisions->first();
        $approved = $events->first(fn ($e) => in_array($e->action, ['approved', 'live'], true));
        $live = $events->firstWhere('action', 'live');

        $firstReview = $firstDecision ? $this->minutes($submitted, $firstDecision->created_at) : null;

        return [
            'platform'               => $story->platform?->name ?? 'Unknown',
            'platformColor'          => $story->platform?->hsl_color,
            'reporterId'             => $story->user_id,
            'reporter'               => $story->user?->name ?? 'Unknown',
            'status'                 => $story->status,
            'day'                    => $submitted->toDateString(),
            'firstReviewMinutes'     => $firstReview,
            'onTarget'               => $firstReview !== null ? $firstReview <= $story->reviewMinutes() : null,
            'approvedAt'             => $approved?->created_at,
            'liveAt'                 => $live?->created_at,
            'submitToApproveMinutes' => $approved ? $this->minutes($submitted, $approved->created_at) : null,
            'approveToLiveMinutes'   => ($approved && $live && $approved->action !== 'live') ? $this->minutes($approved->created_at, $live->created_at) : null,
            'sendBacks'              => $events->where('action', 'changes_requested')->count(),
        ];
    }

    /** What's waiting right now, regardless of the date window. */
    private function queueNow(callable $scope): array
    {
        $waiting = Story::with('storyType')->whereIn('status', Story::QUEUE_STATUSES)->tap($scope)->get();

        $ages = $waiting->map(fn ($s) => [
            'minutes' => $this->minutes($s->submitted_at ?? $s->created_at, now()),
            'target'  => $s->reviewMinutes(),
        ]);

        return [
            'waiting'              => $waiting->count(),
            'overdue'              => $ages->filter(fn ($a) => $a['minutes'] > $a['target'])->count(),
            'oldestWaitingMinutes' => $ages->max('minutes'),
            'sentBack'             => Story::where('status', 'changes_requested')->tap($scope)->count(),
        ];
    }

    private function daily(Collection $rows, Carbon $since, int $days): array
    {
        $platforms = $rows->pluck('platform')->unique()->sort()->values();
        $series = [];

        // Inclusive of today, so stories filed today show up
        for ($i = 0; $i <= $days; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $entry = ['date' => $day];
            foreach ($platforms as $p) {
                $entry[$p] = $rows->where('day', $day)->where('platform', $p)->count();
            }
            $series[] = $entry;
        }

        return [
            'platforms' => $platforms->map(fn ($p) => [
                'name'  => $p,
                'color' => $rows->firstWhere('platform', $p)['platformColor'] ?? null,
            ])->values(),
            'series' => $series,
        ];
    }

    private function byPlatform(Collection $rows): array
    {
        return $rows->groupBy('platform')->map(function (Collection $g, $name) {
            $reviewed = $g->whereNotNull('firstReviewMinutes');
            return [
                'platform'                 => $name,
                'submitted'                => $g->count(),
                'live'                     => $g->whereNotNull('liveAt')->count(),
                'medianFirstReviewMinutes' => $this->median($reviewed->pluck('firstReviewMinutes')),
                'onTargetRate'             => $this->rate($reviewed->where('onTarget', true)->count(), $reviewed->count()),
            ];
        })->sortByDesc('submitted')->values()->all();
    }

    /** Every decision an editor made in the window, timed from the story's submission. */
    private function byEditor(Collection $stories, Carbon $since): array
    {
        $decisions = $stories->flatMap(fn (Story $s) => $s->timeline
            ->whereIn('action', self::DECISIONS)
            ->filter(fn ($e) => $e->created_at->gte($since) && $e->actor_id !== $s->user_id)
            ->map(fn ($e) => [
                'editor'  => $e->actor?->name ?? 'Unknown',
                'action'  => $e->action,
                'minutes' => $this->minutes($s->created_at, $e->created_at),
            ]));

        return $decisions->groupBy('editor')->map(fn (Collection $g, $name) => [
            'editor'        => $name,
            'decisions'     => $g->count(),
            'approved'      => $g->whereIn('action', ['approved', 'live'])->count(),
            'sentBack'      => $g->where('action', 'changes_requested')->count(),
            'rejected'      => $g->where('action', 'rejected')->count(),
            'medianMinutes' => $this->median($g->pluck('minutes')),
        ])->sortByDesc('decisions')->values()->all();
    }

    private function byReporter(Collection $rows): array
    {
        return $rows->groupBy('reporterId')->map(function (Collection $g) {
            $reviewed = $g->whereNotNull('firstReviewMinutes');
            return [
                'reporter'          => $g->first()['reporter'],
                'filed'             => $g->count(),
                'approved'          => $g->whereNotNull('approvedAt')->count(),
                'sentBackRate'      => $this->rate($reviewed->where('sendBacks', '>', 0)->count(), $reviewed->count()),
                'avgRevisionRounds' => $reviewed->count() ? round($reviewed->avg('sendBacks'), 2) : null,
            ];
        })->sortByDesc('filed')->values()->all();
    }

    private function minutes($from, $to): int
    {
        return (int) max(0, round(Carbon::parse($from)->diffInSeconds(Carbon::parse($to), false) / 60));
    }

    private function median(Collection $values): ?float
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->sort()->values();
        $n = $sorted->count();
        if ($n === 0) return null;

        return $n % 2 ? (float) $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole ? round($part / $whole, 3) : null;
    }
}
