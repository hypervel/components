<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderConnectionException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ErrorHandlingTest extends TestCase
{
    use GeminiHelpers;

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid value at contents',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'The model is overloaded. Please try again later.',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    #[DataProvider('overloadedStatuses')]
    public function testTransientUpstreamErrorsFailOverAsOverloaded(int $status): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['code' => $status, 'message' => 'The service is temporarily unavailable.'],
            ], $status),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    /**
     * Provide transient failure statuses.
     */
    public static function overloadedStatuses(): array
    {
        return [[502], [504], [520], [522], [524]];
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 'internal',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function testAFailedInteractionThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error: [internal] The model stopped responding.');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'id' => 'int_123',
                'status' => 'failed',
                'steps' => [],
                'errors' => [['code' => 'internal', 'message' => 'The model stopped responding.']],
            ]),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function testAWithheldAnswerIsReportedAsAContentFilterFinishReason(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'id' => 'int_123',
                'status' => 'failed',
                'steps' => [],
                'errors' => [['code' => 'safety', 'message' => 'The response was blocked.']],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::ContentFilter, $response->steps->last()->finishReason);
    }

    public function testConnectionFailureThrowsAFailoverableProviderConnectionException(): void
    {
        $this->expectException(ProviderConnectionException::class);
        Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function testConnectionFailureFailsOverToTheNextProvider(): void
    {
        config([
            'ai.providers.primary' => ['driver' => 'gemini', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'gemini', 'key' => 'test-key'],
        ]);

        $backup = $this->fakeTextResponse('Recovered on the backup provider');

        $attempts = 0;

        Http::fake(function () use (&$attempts, $backup): PromiseInterface {
            ++$attempts;

            if ($attempts === 1) {
                throw new ConnectionException('Connection refused');
            }

            return $backup;
        });

        $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

        $this->assertSame('Recovered on the backup provider', $response->text);
    }
}
