<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
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
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'max_tokens: must be at least 1',
                ],
            ], 400),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'deepseek',
        );
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'deepseek',
        );
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'deepseek',
        );
    }

    public function test402ResponseThrowsInsufficientCreditsException(): void
    {
        $this->expectException(InsufficientCreditsException::class);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => [
                    'message' => 'Insufficient Balance',
                    'type' => 'insufficient_balance_error',
                    'param' => null,
                    'code' => 'insufficient_balance',
                ],
            ], 402),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'deepseek',
        );
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('DeepSeek Error');
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'deepseek',
        );
    }
}
