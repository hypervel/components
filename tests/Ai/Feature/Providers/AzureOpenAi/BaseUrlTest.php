<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeAzureResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class BaseUrlTest extends TestCase
{
    public function testAzureTextRequestsUseTheV1ResponsesEndpoint(): void
    {
        $this->configureAzureProvider('https://my-resource.cognitiveservices.azure.com', deployment: 'gpt-4o');

        Http::fake(['*' => fakeAzureResponse('Hello from Azure')]);

        $response = agent()->prompt('Hello', provider: 'azure');

        $this->assertSame('Hello from Azure', $response->text);

        $this->azureAssertRequestSent('POST', 'https://my-resource.cognitiveservices.azure.com/openai/v1/responses');
    }

    public function testAzureRequestsDoNotIncludeApiVersionQueryParameter(): void
    {
        $this->configureAzureProvider('https://my-resource.cognitiveservices.azure.com');

        Http::fake(['*' => fakeAzureResponse()]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'api-version'));
    }

    public function testAzureRequestsUseApiKeyHeaderNotBearerToken(): void
    {
        $this->configureAzureProvider('https://my-resource.cognitiveservices.azure.com');

        Http::fake(['*' => fakeAzureResponse()]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('api-key', 'test-key')
            && ! $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    /**
     * Configure the Azure OpenAI endpoint and deployment.
     */
    private function configureAzureProvider(?string $url = null, string $deployment = 'gpt-4o'): void
    {
        config(['ai.providers.azure' => array_filter([
            ...config('ai.providers.azure'),
            'key' => 'test-key',
            'url' => $url,
            'deployment' => $deployment,
        ])]);
    }

    /**
     * Assert that the expected Azure endpoint received a request.
     */
    private function azureAssertRequestSent(string $method, string $url): void
    {
        Http::assertSent(function (Request $request) use ($method, $url): bool {
            $requestUrl = strtok($request->url(), '?');

            return $request->method() === $method
                && $requestUrl === $url;
        });
    }
}
