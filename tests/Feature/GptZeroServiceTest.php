<?php

namespace Tests\Feature;

use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_core\Services\CredentialService;
use hexa_package_gptzero\Services\GptZeroService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class GptZeroServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireInstalledPackage('hexawebsystems/laravel-hexa-package-gptzero', GptZeroService::class);
        Http::preventStrayRequests();
    }

    public function test_connection_uses_bounded_pinned_transport_and_package_credential(): void
    {
        $credentials = $this->mockCredential('fixture-key');
        $requests = [];
        $service = $this->service($credentials, $requests, new OutboundHttpResponse(200, [], '{"documents":[]}'));

        $result = $service->testConnection();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $requests);
        $this->assertSame('https://api.gptzero.me/v2/predict/text', $requests[0]->target->url);
        $this->assertSame('fixture-key', $requests[0]->headers['x-api-key']);
        $this->assertSame(15, $requests[0]->timeoutSeconds);
        $this->assertSame(4 * 1024 * 1024, $requests[0]->maxResponseBytes);
        $this->assertSame('Test connection.', json_decode((string) $requests[0]->body, true, 512, JSON_THROW_ON_ERROR)['document']);
        Http::assertNothingSent();
    }

    public function test_provider_failures_do_not_leak_credentials_or_response_details(): void
    {
        $credentials = $this->mockCredential('fixture-key');
        $requests = [];
        $service = $this->service($credentials, $requests, new OutboundHttpResponse(
            500,
            [],
            '{"error":"fixture-key provider detail"}',
        ));

        $result = $service->testConnection();
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('fixture-key', $serialized);
        $this->assertStringNotContainsString('provider detail', $serialized);
        Http::assertNothingSent();
    }

    public function test_detection_preserves_provider_classification_and_result_shape(): void
    {
        $credentials = $this->mockCredential('fixture-key');
        $requests = [];
        $service = $this->service($credentials, $requests, new OutboundHttpResponse(200, [], json_encode([
            'documents' => [[
                'completely_generated_prob' => 0.9,
                'average_generated_prob' => 0.75,
                'overall_burstiness' => 0.2,
                'sentences' => [['sentence' => 'Fixture sentence.']],
                'predicted_class' => 'AI_ONLY',
            ]],
        ], JSON_THROW_ON_ERROR)));

        $result = $service->detect('Fixture content for a detector request.');

        $this->assertTrue($result['success']);
        $this->assertSame('AI_ONLY', $result['data']['predicted_class']);
        $this->assertSame(0.9, $result['data']['completely_generated_prob']);
        $this->assertSame('Fixture sentence.', $result['data']['sentences'][0]['sentence']);
        $this->assertSame(30, $requests[0]->timeoutSeconds);
        Http::assertNothingSent();
    }

    public function test_oversized_detection_is_rejected_before_transport_without_detail_leakage(): void
    {
        $credentials = $this->mockCredential('fixture-key');
        $requests = [];
        $service = $this->service($credentials, $requests, new OutboundHttpResponse(200, [], '{}'));

        $result = $service->detect(str_repeat('x', 1024 * 1024));

        $this->assertFalse($result['success']);
        $this->assertSame('GPTZero request failed safely.', $result['message']);
        $this->assertSame([], $requests);
        Http::assertNothingSent();
    }

    public function test_service_source_has_no_raw_http_or_exception_detail_fallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/GptZeroService.php');

        $this->assertStringContainsString('SafeOutboundHttpClient', $source);
        $this->assertStringNotContainsString('Facades\\Http', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('->getMessage()', $source);
        $this->assertStringNotContainsString('->body()', $source);
    }

    public function test_settings_controller_writes_api_keys_only_through_credential_vault(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/GptZeroController.php');

        $this->assertStringContainsString("credentials->store('gptzero', 'api_key'", $source);
        $this->assertStringNotContainsString("Setting::setValue('gptzero_api_key', \$validated", $source);
    }

    /** @param list<OutboundHttpRequest> $requests */
    private function service(
        CredentialService $credentials,
        array &$requests,
        OutboundHttpResponse $response,
    ): GptZeroService {
        $client = new SafeOutboundHttpClient(
            new OutboundUrlGuard(static fn (string $host): array => ['93.184.216.34']),
            static function (OutboundHttpRequest $request) use (&$requests, $response): OutboundHttpResponse {
                $requests[] = $request;

                return $response;
            },
        );

        return new GptZeroService($client, $credentials);
    }

    private function mockCredential(string $apiKey): CredentialService
    {
        $credentials = Mockery::mock(CredentialService::class);
        $credentials->shouldReceive('get')->once()->with('gptzero', 'api_key')->andReturn($apiKey);

        return $credentials;
    }
}
