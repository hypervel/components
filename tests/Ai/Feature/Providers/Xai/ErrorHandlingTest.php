<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InvalidApiKeyException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('rejectedApiKeys')]
    public function testARejectedApiKeyHintsAtTheKey(int $status, array $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $this->expectException(InvalidApiKeyException::class);
        $this->expectExceptionMessage('Missing or invalid API key for AI provider [xai].');

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    /**
     * Provide xAI's rejected API key responses.
     */
    public static function rejectedApiKeys(): array
    {
        return [
            'unauthenticated' => [401, ['error' => 'Missing or invalid authorization token.']],
            'incorrect key' => [400, [
                'code' => 'invalid-argument',
                'error' => 'Incorrect API key provided. You can obtain an API key from https://console.x.ai.',
            ]],
        ];
    }

    #[DataProvider('nonJsonFailures')]
    public function testANonJsonErrorBodyKeepsItsExceptionWhenDefaultDecodingThrows(int $status, string $exception): void
    {
        Response::$defaultJsonDecodingFlags = JSON_THROW_ON_ERROR;
        Http::fake(['*' => Http::response('<html>Error</html>', $status)]);

        $this->expectException($exception);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    /**
     * Provide failures before and after the API key check reads the error body.
     */
    public static function nonJsonFailures(): array
    {
        return [[503, ProviderOverloadedException::class], [400, RequestException::class]];
    }
}
