<?php

namespace Tests\Feature;

use hexa_core\Services\CredentialService;
use hexa_package_gptzero\Services\GptZeroService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class GptZeroServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireInstalledPackage("hexawebsystems/laravel-hexa-package-gptzero", GptZeroService::class);
    }

    public function test_connection_uses_package_credential_and_provider_endpoint(): void
    {
        $credentials = Mockery::mock(CredentialService::class);
        $credentials->shouldReceive("get")->once()->with("gptzero", "api_key")->andReturn("test-key");
        $this->app->instance(CredentialService::class, $credentials);

        Http::fake(["*api.gptzero.me/*" => Http::response(["success" => true], 200)]);

        $result = app(GptZeroService::class)->testConnection();

        $this->assertTrue($result["success"]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "api.gptzero.me/v2/predict/text") && $request->hasHeader("x-api-key", "test-key"));
    }
}
