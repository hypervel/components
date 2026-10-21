<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

class ErrorHandlingTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid request: max_output_tokens must be at least 1',
                ],
            ], 400),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'openai');
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'openai');
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'openai');
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenAI Error');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'openai');
    }

    public function testFailedStatusResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenAI Error');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_123',
                'status' => 'failed',
                'model' => 'gpt-5.4',
                'output' => [],
            ], 200),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'openai');
    }

    public function testFailedResponsePreservesTheProviderErrorCode(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenAI Error: [server_error] The response failed.');
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_123',
                'status' => 'failed',
                'model' => 'gpt-5.4',
                'output' => [],
                'error' => ['code' => 'server_error', 'message' => 'The response failed.'],
            ]),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'openai');
    }
}
