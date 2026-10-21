<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class BaseUrlTest extends TestCase
{
    protected string $customUrl = 'http://localhost:1234/v1';

    public function testGroqTextRequestsUseTheConfiguredBaseUrl(): void
    {
        $this->configureGroqProvider($this->customUrl);

        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'model' => 'openai/gpt-oss-20b',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello from local model',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello from local model', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', "{$this->customUrl}/chat/completions");
    }

    public function testGroqRequestsFallBackToTheDefaultBaseUrl(): void
    {
        $this->configureGroqProvider();

        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-456',
                'object' => 'chat.completion',
                'model' => 'openai/gpt-oss-20b',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello from Groq',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 1,
                ],
            ]),
        ]);

        $response = agent()->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello from Groq', $response->text);

        Http::assertSentCount(1);
        $this->assertRequestSent('POST', 'https://api.groq.com/openai/v1/chat/completions');
    }

    /**
     * Configure the provider with an optional custom URL.
     */
    protected function configureGroqProvider(?string $url = null): void
    {
        config(['ai.providers.groq' => array_filter([
            ...config('ai.providers.groq'),
            'key' => 'test-key',
            'url' => $url,
        ])]);
    }

    /**
     * Assert the request method and URL.
     */
    protected function assertRequestSent(string $method, string $url): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === $method
            && $request->url() === $url);
    }
}
