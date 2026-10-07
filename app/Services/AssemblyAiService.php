<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the AssemblyAI REST API.
 *
 * Docs: https://www.assemblyai.com/docs/api-reference
 */
class AssemblyAiService
{
    private PendingRequest $http;

    public function __construct()
    {
        $key = config('services.assemblyai.key');

        if (empty($key)) {
            throw new RuntimeException('ASSEMBLYAI_API_KEY is not set.');
        }

        $this->http = Http::baseUrl('https://api.assemblyai.com/v2')
            ->withHeaders(['Authorization' => $key])
            ->timeout(60);
    }

    // ── 1. Upload raw audio bytes to AssemblyAI CDN ────────────────────────

    /**
     * Uploads a local file path to AssemblyAI and returns their upload URL.
     * AssemblyAI requires the audio to be hosted somewhere accessible to them;
     * uploading directly avoids having to make our private S3 bucket public.
     */
    public function uploadAudio(string $localPath): string
    {
        $response = $this->http
            ->withBody(file_get_contents($localPath), 'application/octet-stream')
            ->post('/upload');

        $this->assertOk($response, 'audio upload');

        return $response->json('upload_url');
    }

    // ── 2. Submit transcript job ───────────────────────────────────────────

    /**
     * Submits a transcription job and returns the AssemblyAI transcript ID.
     *
     * @param string      $audioUrl    URL returned by uploadAudio()
     * @param string      $language    BCP-47 code or 'auto' for auto-detection
     * @param bool        $timestamps  Whether to request word-level timestamps
     * @param bool        $speakers    Whether to enable speaker diarization
     */
    public function submitTranscript(
        string $audioUrl,
        string $language   = 'auto',
        bool   $timestamps = false,
        bool   $speakers   = false,
    ): string {
        $payload = [
            'audio_url'            => $audioUrl,
            'speaker_labels'       => $speakers,
            'auto_highlights'      => false,
        ];

        // AssemblyAI uses 'language_detection' for auto, or a language_code otherwise
        if ($language === 'auto') {
            $payload['language_detection'] = true;
        } else {
            $payload['language_code'] = $language;
        }

        $response = $this->http->post('/transcript', $payload);
        $this->assertOk($response, 'transcript submission');

        return $response->json('id');
    }

    // ── 3. Poll for completion ─────────────────────────────────────────────

    /**
     * Polls a single transcript by ID and returns its current status and data.
     *
     * Possible status values: queued | processing | completed | error
     *
     * @return array{
     *   status: string,
     *   text: string|null,
     *   words: array|null,
     *   utterances: array|null,
     *   audio_duration: float|null,
     *   language_code: string|null,
     *   error: string|null,
     * }
     */
    public function getTranscript(string $transcriptId): array
    {
        $response = $this->http->get("/transcript/{$transcriptId}");
        $this->assertOk($response, 'transcript poll');

        return $response->json();
    }

    // ── 4. Parse completed transcript into our segment shape ───────────────

    /**
     * Converts an AssemblyAI completed transcript into our internal segment format.
     *
     * @return array{
     *   plain_text: string,
     *   word_count: int,
     *   duration: int,
     *   speaker_count: int|null,
     *   segments: array,
     *   language_code: string,
     * }
     */
    public function parseCompleted(array $transcript): array
    {
        $plainText = $transcript['text'] ?? '';
        $duration  = (int) round($transcript['audio_duration'] ?? 0);
        $wordCount = str_word_count($plainText);
        $langCode  = $transcript['language_code'] ?? 'en';

        // Prefer utterances (speaker-labelled chunks) when available
        $rawSegments  = $transcript['utterances'] ?? [];
        $hasSpeakers  = !empty($rawSegments);

        // Fall back to words grouped by sentence if no utterances
        if (!$hasSpeakers) {
            $rawSegments = $this->groupWordsIntoSegments($transcript['words'] ?? []);
        }

        $segments = array_map(function (array $seg) use ($hasSpeakers): array {
            return [
                'speaker' => $hasSpeakers ? ($seg['speaker'] ?? null) : null,
                'start'   => (int) round(($seg['start'] ?? 0) / 1000),   // ms → s
                'end'     => (int) round(($seg['end']   ?? 0) / 1000),
                'text'    => trim($seg['text'] ?? ''),
            ];
        }, $rawSegments);

        $speakerCount = $hasSpeakers
            ? count(array_unique(array_column($segments, 'speaker')))
            : null;

        return [
            'plain_text'    => $plainText,
            'word_count'    => $wordCount,
            'duration'      => $duration,
            'speaker_count' => $speakerCount,
            'segments'      => $segments,
            'language_code' => $langCode,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Groups word-level timestamps into rough sentence segments.
     * Used as a fallback when speaker diarization is off.
     */
    private function groupWordsIntoSegments(array $words, int $pauseMs = 1500): array
    {
        $segments = [];
        $current  = [];

        foreach ($words as $i => $word) {
            $current[] = $word;

            $nextWord   = $words[$i + 1] ?? null;
            $isLast     = $nextWord === null;
            $longPause  = $nextWord && ($nextWord['start'] - $word['end']) > $pauseMs;
            $endOfSent  = str_ends_with(trim($word['text']), '.');

            if ($isLast || $longPause || ($endOfSent && count($current) > 5)) {
                $segments[] = [
                    'start' => $current[0]['start'],
                    'end'   => end($current)['end'],
                    'text'  => implode(' ', array_column($current, 'text')),
                ];
                $current = [];
            }
        }

        return $segments;
    }

    private function assertOk(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "AssemblyAI {$context} failed [{$response->status()}]: " . $response->body()
            );
        }
    }
}