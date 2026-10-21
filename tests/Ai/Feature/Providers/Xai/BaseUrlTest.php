<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function testXaiTextRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.xai' => array_filter([
            ...config('ai.providers.xai'),
            'key' => 'test-key',
            'url' => $this->customUrl,
        ])]);

        Http::fake([
            '*' => Http::response(fakeXaiBaseUrlResponseData('Hello from local model')),
        ]);

        $response = agent()->prompt('Hello', provider: 'xai');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "{$this->customUrl}/responses");
    }

    public function testXaiRequestsFallBackToTheDefaultBaseUrl(): void
    {
        config(['ai.providers.xai' => array_filter([
            ...config('ai.providers.xai'),
            'key' => 'test-key',
        ])]);

        Http::fake([
            '*' => Http::response(fakeXaiBaseUrlResponseData('Hello from xAI')),
        ]);

        $response = agent()->prompt('Hello', provider: 'xai');

        $this->assertSame('Hello from xAI', $response->text);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.x.ai/v1/responses');
    }
}

/**
 * Build a completed xAI response.
 */
function fakeXaiBaseUrlResponseData(string $text): array
{
    return [
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ];
}
