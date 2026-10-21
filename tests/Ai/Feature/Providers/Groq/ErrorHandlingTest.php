<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

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
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'max_tokens: must be at least 1',
                ],
            ], 400),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );
    }

    public function testFlexTierCapacityExceededResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'error' => [
                    'type' => 'capacity_exceeded',
                    'message' => 'Flex tier capacity exceeded. Please try again later.',
                ],
            ], 498),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );
    }

    public function testUndocumentedGatewayStatusCodeDoesNotFailOver(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.groq.com/*' => Http::response('gateway timeout', 524),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Groq Error');
        Http::fake([
            'api.groq.com/*' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'groq',
        );
    }
}
