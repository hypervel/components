<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use LogicException;

class TranscriptionTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testTranscriptionSendsPromptFromProviderOptions(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['prompt' => 'Hypervel Forge and Vapor'])
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && str_contains($request->body(), 'prompt')
            && str_contains($request->body(), 'Hypervel Forge and Vapor'));
    }

    public function testTranscriptionThrowsWhenPromptProviderOptionIsUsedWithDiarizedModels(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OpenAI does not support the `prompt` option for diarized transcriptions.');
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['prompt' => 'Hypervel Forge and Vapor'])
            ->diarize()
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');
    }

    public function testTranscriptionRequestPostsToCorrectEndpoint(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
    }

    public function testTranscriptionIncludesModelInRequest(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'gpt-4o-transcribe'));
    }

    public function testTranscriptionStripsDiarizeSuffixFromModelWhenDiarizeIsOff(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'gpt-4o-transcribe')
            && ! str_contains($request->body(), 'gpt-4o-transcribe-diarize'));
    }

    public function testTranscriptionResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse('Hello, world!')]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai');

        $this->assertSame('Hello, world!', $response->text);
        $this->assertSame('openai', $response->meta->provider);
    }

    public function testTranscriptionUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
    }

    public function testTranscriptionSendsLanguageWhenProvided(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('en')
            ->generate(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => collect($request->data())->firstWhere('name', 'language')['contents'] === 'en');
    }

    public function testTranscriptionRequestSendsBearerToken(): void
    {
        Http::fake(['*' => fakeOpenAiTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testTranscriptionRateLimitResponseThrowsRateLimitedException(): void
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

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
    }

    public function testTranscriptionOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded.',
                ],
            ], 503),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
    }

    public function testTranscriptionHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid file format',
                ],
            ], 400),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
    }

    public function testTranscriptionReportsTheBilledAudioSecondsForDurationBasedModels(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello, world!',
            'usage' => ['type' => 'duration', 'seconds' => 12.5],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai', model: 'whisper-1');

        $this->assertSame(12.5, $response->usage->audioSeconds);
        $this->assertSame(0, $response->usage->inputTokens);
    }

    public function testTranscriptionLeavesTheAudioSecondsNullForTokenBasedModels(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello, world!',
            'usage' => [
                'type' => 'tokens',
                'input_tokens' => 14,
                'output_tokens' => 4,
                'input_token_details' => ['text_tokens' => 0, 'audio_tokens' => 14],
            ],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

        $this->assertNull($response->usage->audioSeconds);
        $this->assertSame(14, $response->usage->inputTokens);
    }

    public function testDiarizedTranscriptionReportsTheAudioDurationWhenUsageIsTokenBased(): void
    {
        Http::fake(['*' => Http::response([
            'task' => 'transcribe',
            'duration' => 42.7,
            'text' => 'Hello, world!',
            'segments' => [],
            'usage' => ['type' => 'tokens', 'input_tokens' => 14, 'output_tokens' => 4, 'total_tokens' => 18],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->diarize()
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

        $this->assertSame(42.7, $response->usage->audioSeconds);
        $this->assertSame(14, $response->usage->inputTokens);
    }
}

function fakeOpenAiTranscriptionResponse(string $text = 'Hello, world!'): PromiseInterface
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'input_tokens' => 10,
            'total_tokens' => 15,
        ],
    ]);
}
