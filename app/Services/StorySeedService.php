<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generates proposed headline + summary from a transcript using OpenAI.
 * Swap the underlying model/provider here without touching job logic.
 */
class StorySeedService
{
    private string $model;

    public function __construct()
    {
        $this->model = config('services.openai.model', 'gpt-4o-mini');
    }

    /**
     * Given plain transcript text, return ['headline' => ..., 'summary' => ...].
     */
    public function generateSeeds(string $transcriptText, string $platform = ''): array
    {
        $platformHint = $platform
            ? "The content is for the {$platform} platform. "
            : '';

        $prompt = <<<PROMPT
You are an experienced broadcast news editor.

{$platformHint}Based on the following transcript, generate:
1. A concise, compelling NEWS HEADLINE (max 20 words, no clickbait, factual).
2. A SHORT SUMMARY for editors (3–5 sentences covering who, what, where, when, why).

Respond ONLY with valid JSON in this exact shape, no markdown fences:
{"headline": "...", "summary": "..."}

TRANSCRIPT:
{$transcriptText}
PROMPT;

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'temperature' => 0.3,
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI request failed: ' . $response->body());
        }

        $content = $response->json('choices.0.message.content', '');
        $decoded = json_decode(trim($content), true);

        if (!isset($decoded['headline'], $decoded['summary'])) {
            throw new RuntimeException('Unexpected OpenAI response shape: ' . $content);
        }

        return [
            'headline' => $decoded['headline'],
            'summary'  => $decoded['summary'],
        ];
    }
}