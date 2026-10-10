<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ErrorHandlingTest extends TestCase
{
    /**
     * Configure provider credentials for error and failover requests.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
        $app->make('config')->set('ai.providers.openai.key', 'backup-key');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Bad request']], 400)]);

        $this->expectException(RequestException::class);
        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

        $this->expectException(RateLimitedException::class);
        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        $this->expectException(ProviderOverloadedException::class);
        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
    }

    public function test402ResponseThrowsInsufficientCreditsException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'code' => 402,
                    'message' => 'Insufficient credits. Add more credits at https://openrouter.ai/credits',
                ],
            ], 402),
        ]);

        $this->expectException(InsufficientCreditsException::class);
        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
    }

    #[DataProvider('bodyErrors')]
    public function testErrorIn200ResponseThrowsAiException(array $error, bool $choiceLevel, string $exceptionClass): void
    {
        $data = $choiceLevel
            ? ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Partial output'], 'finish_reason' => 'error', 'error' => $error]]]
            : ['error' => $error];

        Http::fake(['openrouter.ai/*' => Http::response($data)]);

        $exception = null;

        try {
            (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
        } catch (AiException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(AiException::class, $exception);
        $this->assertSame($exceptionClass, $exception::class);
        $this->assertSame(sprintf('OpenRouter Error: [%s] %s', $error['type'] ?? $error['code'], $error['message']), $exception->getMessage());
    }

    /**
     * Provide ordinary and failoverable error bodies.
     */
    public static function bodyErrors(): array
    {
        return [
            [['type' => 'invalid_request_error', 'message' => 'The model does not exist.'], false, AiException::class],
            [['code' => 429, 'message' => 'Rate limited'], false, RateLimitedException::class],
            [['code' => 402, 'message' => 'Insufficient credits'], false, InsufficientCreditsException::class],
            [['code' => 503, 'message' => 'Provider overloaded'], true, ProviderOverloadedException::class],
            [['code' => 502, 'message' => 'Provider disconnected mid-stream'], true, ProviderOverloadedException::class],
            [['code' => 400, 'message' => 'Invalid request'], true, AiException::class],
        ];
    }

    public function testBufferedBodyErrorsTryTheNextProvider(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['code' => 503, 'message' => 'Provider overloaded']]),
            'api.openai.com/*' => fakeOpenAiResponse('Backup answer'),
        ]);

        $response = (new AssistantAgent)->prompt('Hello', provider: ['openrouter', 'openai']);

        $this->assertSame('Backup answer', $response->text);
        $this->assertSame('openai', $response->meta->provider);
        Http::assertSentCount(2);
    }
}
