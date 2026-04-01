<?php

namespace hexa_package_gptzero\Services;

use hexa_core\Models\Setting;
use hexa_core\Services\GenericService;
use Illuminate\Support\Facades\Http;

/**
 * GptZeroService — AI content detection via GPTZero API.
 *
 * Detects AI-generated text with per-sentence probability scoring.
 * Free tier: 10,000 words/month. API: api.gptzero.me
 */
class GptZeroService
{
    protected GenericService $generic;

    /**
     * @param GenericService $generic
     */
    public function __construct(GenericService $generic)
    {
        $this->generic = $generic;
    }

    /**
     * Check if GPTZero is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return Setting::getValue('gptzero_enabled', config('gptzero.enabled', true));
    }

    /**
     * Check if debug mode is on (sends only first 3 sentences).
     *
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return (bool) Setting::getValue('gptzero_debug_mode', false);
    }

    /**
     * Get the API key.
     *
     * @return string|null
     */
    public function getApiKey(): ?string
    {
        return Setting::getValue('gptzero_api_key');
    }

    /**
     * Detect AI-generated content.
     *
     * @param string $text Plain text to analyze
     * @return array{success: bool, message: string, data?: array}
     */
    public function detect(string $text): array
    {
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            return ['success' => false, 'message' => 'GPTZero API key not configured.'];
        }

        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'GPTZero is disabled.'];
        }

        // Debug mode: only send first 3 sentences
        if ($this->isDebugMode()) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $text, 4);
            $text = implode(' ', array_slice($sentences, 0, 3));
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post(config('gptzero.api_url', 'https://api.gptzero.me/v2/predict/text'), [
                'document' => $text,
            ]);

            if (!$response->successful()) {
                $error = $response->json('error') ?? $response->body();
                return ['success' => false, 'message' => 'GPTZero API error: ' . (is_string($error) ? $error : json_encode($error))];
            }

            $data = $response->json();

            return [
                'success' => true,
                'message' => 'Detection complete.',
                'data' => [
                    'completely_generated_prob' => $data['documents'][0]['completely_generated_prob'] ?? null,
                    'average_generated_prob' => $data['documents'][0]['average_generated_prob'] ?? null,
                    'overall_burstiness' => $data['documents'][0]['overall_burstiness'] ?? null,
                    'sentences' => $data['documents'][0]['sentences'] ?? [],
                    'predicted_class' => $data['documents'][0]['predicted_class'] ?? null,
                    'raw' => $data,
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'GPTZero request failed: ' . $e->getMessage()];
        }
    }

    /**
     * Test the API connection with a simple request.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $result = $this->detect('The quick brown fox jumps over the lazy dog. This is a simple test sentence written by a human.');
        if ($result['success']) {
            return ['success' => true, 'message' => 'GPTZero API connected. AI probability: ' . round(($result['data']['completely_generated_prob'] ?? 0) * 100) . '%'];
        }
        return $result;
    }
}
