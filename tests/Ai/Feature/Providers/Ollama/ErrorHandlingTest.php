<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

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
     * Configure the local Ollama provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
        $app->make('config')->set('ai.providers.ollama.url', 'http://localhost:11434');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'model not found',
            ], 400),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'ollama',
        );
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'rate limit exceeded',
            ], 429),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'ollama',
        );
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'server overloaded',
            ], 503),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'ollama',
        );
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Ollama Error');
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'model "unknown-model" not found',
            ], 200),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'ollama',
        );
    }
}
