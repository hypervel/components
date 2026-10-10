<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ErrorHandlingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'message' => 'Invalid API key',
                'type' => 'authentication_error',
            ], 401),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
            ], 429),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'message' => 'The server is currently overloaded. Please try again later.',
                'type' => 'server_error',
            ], 503),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    #[DataProvider('errorResponses')]
    public function testErrorIn200ResponseThrowsAiException(array $response): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Mistral Error: [server_error] Internal server error');
        Http::fake([
            '*' => Http::response($response, 200),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    /**
     * Provide the error envelopes accepted by the response validator.
     */
    public static function errorResponses(): array
    {
        $error = ['type' => 'server_error', 'message' => 'Internal server error'];

        return [
            'nested' => [['object' => 'error', 'error' => $error]],
            'flat' => [['object' => 'error', ...$error]],
        ];
    }
}
