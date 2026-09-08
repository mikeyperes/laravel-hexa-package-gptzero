<?php

namespace hexa_package_gptzero\Services;

use hexa_core\AI\Contracts\AiTransactionRecorder;
use hexa_core\Models\Setting;
use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_core\Services\CredentialService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-content detection through GPTZero's text prediction endpoint.
 */
class GptZeroService
{
    private const DEFAULT_ENDPOINT = 'https://api.gptzero.me/v2/predict/text';

    private const CONNECTION_TIMEOUT_SECONDS = 15;

    private const DETECTION_TIMEOUT_SECONDS = 30;

    private const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly SafeOutboundHttpClient $http,
        private readonly CredentialService $credentials,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue('gptzero_enabled', config('gptzero.enabled', true));
    }

    public function isDebugMode(): bool
    {
        return (bool) Setting::getValue('gptzero_debug_mode', false);
    }

    public function getApiKey(): ?string
    {
        return $this->credentials->get('gptzero', 'api_key')
            ?: Setting::getValue('gptzero_api_key');
    }

    /** @return array{success: bool, message: string, data?: array} */
    public function detect(string $text): array
    {
        $apiKey = $this->validApiKey($this->getApiKey());
        if ($apiKey === null) {
            return ['success' => false, 'message' => 'GPTZero API key not configured.'];
        }

        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'GPTZero is disabled.'];
        }

        $text = $this->debugText($text);

        try {
            $response = $this->requestDetection($apiKey, $text, self::DETECTION_TIMEOUT_SECONDS, 'detector.scan');
        } catch (Throwable $exception) {
            $this->logTransportFailure('scan', $exception);

            return ['success' => false, 'message' => 'GPTZero request failed safely.'];
        }

        if (! $response->successful()) {
            return ['success' => false, 'message' => "GPTZero API error ({$response->status})."];
        }

        $data = $this->jsonObject($response);
        $document = is_array($data['documents'][0] ?? null) ? $data['documents'][0] : [];

        return [
            'success' => true,
            'message' => 'Detection complete.',
            'data' => [
                'completely_generated_prob' => $document['completely_generated_prob'] ?? null,
                'average_generated_prob' => $document['average_generated_prob'] ?? null,
                'overall_burstiness' => $document['overall_burstiness'] ?? null,
                'sentences' => $document['sentences'] ?? [],
                'predicted_class' => $document['predicted_class'] ?? null,
                'raw' => $data,
            ],
        ];
    }

    /** @return array{success: bool, message: string} */
    public function testConnection(): array
    {
        $apiKey = $this->validApiKey($this->getApiKey());
        if ($apiKey === null) {
            return ['success' => false, 'message' => 'GPTZero API key not configured.'];
        }

        try {
            $response = $this->requestDetection(
                $apiKey,
                'Test connection.',
                self::CONNECTION_TIMEOUT_SECONDS,
                'detector.connection_test',
            );
        } catch (Throwable $exception) {
            $this->logTransportFailure('connection_test', $exception);

            return ['success' => false, 'message' => 'GPTZero connection failed safely.'];
        }

        if ($response->successful()) {
            return ['success' => true, 'message' => 'GPTZero API connected successfully.'];
        }

        return ['success' => false, 'message' => "GPTZero API error ({$response->status})."];
    }

    private function requestDetection(string $apiKey, string $text, int $timeout, string $operation): OutboundHttpResponse
    {
        $endpoint = (string) config('gptzero.api_url', self::DEFAULT_ENDPOINT);
        $units = ['characters' => mb_strlen($text), 'words' => str_word_count($text)];
        $span = app(AiTransactionRecorder::class)->start([
            'provider' => 'gptzero',
            'package' => 'hexawebsystems/laravel-hexa-package-gptzero',
            'model' => 'gptzero-ai-detector-v2',
            'operation' => $operation,
            'endpoint' => parse_url($endpoint, PHP_URL_PATH) ?: '/v2/predict/text',
            'request_metadata' => array_merge($units, ['timeout_seconds' => $timeout]),
        ]);

        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-api-key' => $apiKey,
                ],
                'body' => json_encode(['document' => $text], JSON_THROW_ON_ERROR),
                'timeout' => $timeout,
                'max_bytes' => self::MAX_RESPONSE_BYTES,
                'max_redirects' => 0,
            ]);
            $payload = $this->jsonObject($response);
            $documents = is_array($payload['documents'] ?? null) ? $payload['documents'] : [];
            $firstDocument = is_array($documents[0] ?? null) ? $documents[0] : [];
            $attributes = [
                'provider_request_id' => $response->headerValues('x-request-id')[0] ?? null,
                'http_status' => $response->status,
                'usage' => $units,
                'response_metadata' => [
                    'document_count' => count($documents),
                    'predicted_class' => $firstDocument['predicted_class'] ?? null,
                ],
            ];

            if ($response->successful()) {
                $span->succeed($attributes);
            } else {
                $span->fail('GPTZero request failed.', array_merge($attributes, [
                    'error_type' => 'gptzero_http_error',
                ]));
            }

            return $response;
        } catch (Throwable $exception) {
            $span->fail(
                $exception instanceof OutboundHttpException ? $exception : 'GPTZero request failed.',
                ['usage' => $units],
            );

            throw $exception;
        }
    }

    private function debugText(string $text): string
    {
        if (! $this->isDebugMode()) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text, 4) ?: [];

        return implode(' ', array_slice($sentences, 0, 3));
    }

    /** @return array<string, mixed> */
    private function jsonObject(OutboundHttpResponse $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function validApiKey(?string $apiKey): ?string
    {
        $apiKey = trim((string) $apiKey);

        return $apiKey !== ''
            && strlen($apiKey) <= 4096
            && preg_match('/[\x00-\x1f\x7f]/', $apiKey) !== 1
                ? $apiKey
                : null;
    }

    private function logTransportFailure(string $operation, Throwable $exception): void
    {
        Log::warning('GPTZero request failed safely', [
            'operation' => $operation,
            'failure_code' => $exception instanceof OutboundHttpException
                ? $exception->failureCode()
                : 'request_failed',
        ]);
    }
}
