<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTranscriptionJob;
use App\Models\Transcription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;

class TranscriptionController extends Controller
{
    // Language labels keyed by BCP-47 code
    private const LANGUAGE_LABELS = [
        'auto' => 'Auto-detect',
        'en'   => 'English',
        'sw'   => 'Swahili',
        'fr'   => 'French',
        'ar'   => 'Arabic',
        'es'   => 'Spanish',
        'pt'   => 'Portuguese',
    ];

    // ── GET /api/transcriptions ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $transcriptions = Transcription::forUser($user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($tx) => $this->listShape($tx));

        $stats = [
            'total'              => $transcriptions->count(),
            'done'               => $transcriptions->where('status', 'done')->count(),
            'processingMinutes'  => (int) round(
                Transcription::forUser($user->id)
                    ->where('status', 'done')
                    ->sum('duration') / 60
            ),
        ];

        return response()->json([
            'data' => [
                'transcriptions' => $transcriptions,
                'stats'          => $stats,
            ],
        ]);
    }

    // ── POST /api/transcriptions ────────────────────────────────────────────

    /**
     * Accepts the audio upload, stores it, records the DB row,
     * and dispatches ProcessTranscriptionJob — returns immediately (202).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'audio'         => ['required', File::types(['mp3', 'mp4', 'wav', 'ogg', 'webm', 'flac', 'm4a'])->max(200 * 1024)],
            'title'         => ['required', 'string', 'max:255'],
            'language'      => ['nullable', 'string', 'max:10'],
            'timestamps'    => ['nullable', 'boolean'],
            'diarization'   => ['nullable', 'boolean'],
        ]);

        $user     = Auth::user();
        $file     = $request->file('audio');
        $language = $request->input('language', 'auto');

        // Store audio under a namespaced private path
        $storedPath = $file->storeAs(
            "transcriptions/{$user->id}",
            Str::uuid() . '.' . $file->getClientOriginalExtension(),
            'private'
        );

        $tx = Transcription::create([
            'title'            => $request->input('title'),
            'audio_filename'   => $file->getClientOriginalName(),
            'audio_path'       => $storedPath,
            'audio_size'       => $file->getSize(),
            'language'         => $language,
            'language_label'   => self::LANGUAGE_LABELS[$language] ?? ucfirst($language),
            'has_timestamps'   => filter_var($request->input('timestamps', false), FILTER_VALIDATE_BOOLEAN),
            'has_speakers'     => filter_var($request->input('diarization', false), FILTER_VALIDATE_BOOLEAN),
            'status'           => 'processing',
            'processing_stage' => 'queued',
            'progress_percent' => 5,
            'user_id'          => $user->id,
        ]);

        // Fire and forget — the frontend will poll for progress
        ProcessTranscriptionJob::dispatch($tx->id, $user->platform ?? '');

        return response()->json(['data' => $this->detailShape($tx)], 202);
    }

    // ── GET /api/transcriptions/{id} ────────────────────────────────────────

    public function show(string $id): JsonResponse
    {
        $tx = $this->findOwned($id);

        return response()->json(['data' => $this->detailShape($tx)]);
    }

    // ── PATCH /api/transcriptions/{id}/seeds ───────────────────────────────

    /**
     * Allows the editor to save their edited headline / summary back.
     */
    public function updateSeeds(Request $request, string $id): JsonResponse
    {
        $tx = $this->findOwned($id);

        $request->validate([
            'proposedHeadline' => ['nullable', 'string', 'max:500'],
            'proposedSummary'  => ['nullable', 'string', 'max:5000'],
        ]);

        $tx->update(array_filter([
            'proposed_headline' => $request->input('proposedHeadline'),
            'proposed_summary'  => $request->input('proposedSummary'),
        ], fn ($v) => $v !== null));

        return response()->json(['data' => $this->detailShape($tx->fresh())]);
    }

    // ── DELETE /api/transcriptions/{id} ────────────────────────────────────

    public function destroy(string $id): JsonResponse
    {
        $tx = $this->findOwned($id);
        Storage::disk('private')->delete($tx->audio_path);
        $tx->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function findOwned(string $id): Transcription
    {
        return Transcription::forUser(Auth::id())
            ->findOrFail($id);
    }

    /** Shape used in the index list (no full segments). */
    private function listShape(Transcription $tx): array
    {
        return [
            'id'            => $tx->id,
            'title'         => $tx->title,
            'audioFilename' => $tx->audio_filename,
            'duration'      => $tx->duration,
            'language'      => $tx->language,
            'languageLabel' => $tx->language_label,
            'status'        => $tx->status,
            'wordCount'     => $tx->word_count,
            'createdAt'     => $tx->created_at->toIso8601String(),
            'createdBy'     => $tx->user->name ?? 'Unknown',
        ];
    }

    /** Full shape used on the detail page. */
    private function detailShape(Transcription $tx): array
    {
        return [
            'id'                   => $tx->id,
            'title'                => $tx->title,
            'audioFilename'        => $tx->audio_filename,
            'duration'             => $tx->duration,
            'language'             => $tx->language,
            'languageLabel'        => $tx->language_label,
            'status'               => $tx->status,
            'processingStage'      => $tx->processing_stage,
            'progressPercent'      => $tx->progress_percent,
            'estimatedSecondsLeft' => $tx->estimated_seconds_left,
            'wordCount'            => $tx->word_count,
            'speakerCount'         => $tx->speaker_count,
            'hasSpeakers'          => $tx->has_speakers,
            'hasTimestamps'        => $tx->has_timestamps,
            'createdAt'            => $tx->created_at->toIso8601String(),
            'createdBy'            => $tx->user->name ?? 'Unknown',
            'plainText'            => $tx->plain_text,
            'segments'             => $tx->segments,
            'proposedHeadline'     => $tx->proposed_headline,
            'proposedSummary'      => $tx->proposed_summary,
        ];
    }
}