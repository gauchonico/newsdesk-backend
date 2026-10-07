<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Services\AssemblyAiService;
use App\Services\StorySeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ProcessTranscriptionJob
 * ────────────────────────────────────────────────────────────────────────────
 * Pipeline:
 *   1. Upload audio file to AssemblyAI CDN         → stage: transcribing  (10%)
 *   2. Submit transcript job to AssemblyAI         → stage: transcribing  (15%)
 *   3. Poll AssemblyAI until complete              → stage: transcribing  (15–80%)
 *   4. Parse result, persist segments              → stage: summarising   (85%)
 *   5. Generate AI headline + summary (OpenAI)     → stage: summarising   (95%)
 *   6. Persist everything, mark done               → stage: done         (100%)
 *
 * The frontend polls GET /api/transcriptions/{id} every 3 s;
 * progress_percent and estimated_seconds_left are updated in place.
 */
class ProcessTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry up to 2 times on unexpected failure (e.g. transient network error).
     * AssemblyAI polling failures are handled internally and don't re-queue.
     */
    public int $tries = 2;

    /**
     * Give the job up to 30 minutes — long audio files take time.
     */
    public int $timeout = 1800;

    // ── Polling config ────────────────────────────────────────────────────

    /** Seconds to wait between AssemblyAI status polls */
    private const POLL_INTERVAL = 5;

    /** Max polls before giving up (~25 min) */
    private const MAX_POLLS = 300;

    // ── Constructor ───────────────────────────────────────────────────────

    public function __construct(
        private readonly string $transcriptionId,
        private readonly string $platform = '',
    ) {}

    // ── Handle ────────────────────────────────────────────────────────────

    public function handle(AssemblyAiService $assemblyAi, StorySeedService $seedService): void
    {
        $tx = Transcription::findOrFail($this->transcriptionId);

        try {
            // ── Step 1: Upload audio to AssemblyAI ────────────────────────
            $tx->markStage('transcribing', 10, null);

            $localPath = Storage::disk('private')->path($tx->audio_path);
            $uploadUrl = $assemblyAi->uploadAudio($localPath);

            // ── Step 2: Submit transcript job ─────────────────────────────
            $tx->markStage('transcribing', 15, null);

            $transcriptId = $assemblyAi->submitTranscript(
                audioUrl:   $uploadUrl,
                language:   $tx->language,
                timestamps: $tx->has_timestamps,
                speakers:   $tx->has_speakers,
            );

            $tx->update(['assemblyai_transcript_id' => $transcriptId]);

            // ── Step 3: Poll until AssemblyAI is done ─────────────────────
            $result = $this->pollUntilDone($assemblyAi, $transcriptId, $tx);

            // ── Step 4: Parse segments ────────────────────────────────────
            $tx->markStage('summarising', 85, 10);

            $parsed = $assemblyAi->parseCompleted($result);

            // ── Step 5: Generate AI story seeds ───────────────────────────
            $tx->markStage('summarising', 95, 5);

            $seeds = ['proposed_headline' => null, 'proposed_summary' => null];

            try {
                $generated = $seedService->generateSeeds($parsed['plain_text'], $this->platform);
                $seeds = [
                    'proposed_headline' => $generated['headline'],
                    'proposed_summary'  => $generated['summary'],
                ];
            } catch (Throwable $e) {
                // Seeds are nice-to-have; don't fail the whole job for this
                Log::warning("StorySeed generation failed for {$tx->id}: " . $e->getMessage());
            }

            // ── Step 6: Persist everything ───────────────────────────────
            $tx->markDone(array_merge([
                'plain_text'    => $parsed['plain_text'],
                'word_count'    => $parsed['word_count'],
                'duration'      => $parsed['duration'],
                'speaker_count' => $parsed['speaker_count'],
                'segments'      => $parsed['segments'],
            ], $seeds));

            // Clean up the local audio file after successful processing
            Storage::disk('private')->delete($tx->audio_path);

        } catch (Throwable $e) {
            Log::error("ProcessTranscriptionJob failed for {$tx->id}: " . $e->getMessage());
            $tx->markFailed($e->getMessage());

            // Re-throw so Laravel can retry if $this->tries allows it
            throw $e;
        }
    }

    // ── Private: polling loop ─────────────────────────────────────────────

    /**
     * Polls AssemblyAI with exponential-ish back-off and updates progress.
     * Returns the raw completed transcript array on success.
     *
     * @throws \RuntimeException on error status or timeout
     */
    private function pollUntilDone(
        AssemblyAiService $assemblyAi,
        string            $transcriptId,
        Transcription     $tx,
    ): array {
        $pollCount    = 0;
        $startTime    = time();

        while ($pollCount < self::MAX_POLLS) {
            sleep(self::POLL_INTERVAL);
            $pollCount++;

            $data   = $assemblyAi->getTranscript($transcriptId);
            $status = $data['status'] ?? 'queued';

            if ($status === 'error') {
                throw new \RuntimeException('AssemblyAI error: ' . ($data['error'] ?? 'unknown'));
            }

            if ($status === 'completed') {
                return $data;
            }

            // Update progress estimate while transcribing
            // AssemblyAI typically takes ~15–30% of the audio duration to transcribe
            $elapsed   = time() - $startTime;
            $duration  = $tx->duration ?? ($elapsed * 4); // rough guess if unknown
            $estimated = max(0, (int) ($duration * 0.25) - $elapsed);
            $pct       = min(79, 15 + (int) (($elapsed / max(1, $duration * 0.25)) * 65));

            $tx->markStage('transcribing', $pct, $estimated);
        }

        throw new \RuntimeException('AssemblyAI polling timed out after ' . self::MAX_POLLS . ' attempts.');
    }
}