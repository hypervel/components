<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

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
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid API key',
                ],
            ], 401),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('xAI Error');
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function testFailedStatusResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('xAI Error: [server_error] The response failed.');
        Http::fake([
            '*' => Http::response([
                'id' => 'resp_123',
                'object' => 'response',
                'status' => 'failed',
                'error' => [
                    'code' => 'server_error',
                    'message' => 'The response failed.',
                ],
                'output' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ], 200),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }
}
