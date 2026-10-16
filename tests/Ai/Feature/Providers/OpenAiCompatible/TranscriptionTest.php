<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAiCompatible;

use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class TranscriptionTest extends TestCase
{
    /**
     * Configure the OpenAI-compatible provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai-compatible', [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => [
                'text' => ['default' => 'local-model'],
                'transcription' => ['default' => 'local-whisper'],
            ],
        ]);
    }

    public function testTranscriptionPostsAudioToTheTranscriptionsEndpointAsMultipart(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:1234/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
    }

    public function testTranscriptionUsesTheConfiguredDefaultModel(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'local-whisper'));
    }

    public function testTranscriptionThrowsWhenNoDefaultModelIsConfiguredAndNoneIsPassed(): void
    {
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => ['text' => ['default' => 'local-model']],
        ]]);

        Http::fake();

        $exception = null;

        try {
            Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
                ->generate(provider: 'openai-compatible');
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('requires a default transcription model', $exception->getMessage());

        Http::assertNothingSent();
    }

    public function testTranscriptionSendsLanguageWhenProvided(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Bonjour'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('fr')
            ->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'name="language"'));
    }

    public function testTranscriptionOmitsLanguageWhenNotProvided(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language"'));
    }

    public function testTranscriptionRequestsTheDiarizedResponseFormatWhenDiarizing(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hi there',
            'segments' => [['text' => 'Hi there', 'speaker' => 'speaker_1', 'start' => 0.5, 'end' => 1.5]],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->diarize()
            ->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'diarized_json'));

        $this->assertCount(1, $response->segments);
        $this->assertSame('speaker_1', $response->segments->first()->speaker);
        $this->assertSame(0.5, $response->segments->first()->startSeconds);
    }

    public function testTranscriptionProviderOptionsOverrideTheDefaultResponseFormat(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['response_format' => 'verbose_json', 'timestamp_granularities' => ['segment']])
            ->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'verbose_json')
            && ! str_contains($request->body(), "\r\n\r\njson\r\n"));
    }

    public function testTranscriptionReadsChatCompletionStyleUsageKeys(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hi',
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        $this->assertSame(12, $response->usage->inputTokens);
        $this->assertSame(3, $response->usage->outputTokens);
    }

    public function testTranscriptionReadsOpenaiStyleUsageKeys(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hi',
            'usage' => ['input_tokens' => 7, 'output_tokens' => 2],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        $this->assertSame(7, $response->usage->inputTokens);
        $this->assertSame(2, $response->usage->outputTokens);
    }

    public function testTranscriptionUsesTheAudioNameForTheUploadFilename(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::of((new Base64Audio(base64_encode('fake-audio'), 'audio/wav'))->as('meeting.wav'))
            ->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'filename="meeting.wav"'));
    }

    public function testTranscriptionDerivesTheUploadFilenameFromTheMimeType(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/flac')->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'filename="audio.flac"'));
    }

    public function testTranscriptionResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        $this->assertSame('Hello, world!', $response->text);
        $this->assertCount(0, $response->segments);
        $this->assertSame('openai-compatible', $response->meta->provider);
        $this->assertSame('local-whisper', $response->meta->model);
    }

    public function testTranscriptionSendsTheBearerToken(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testTranscriptionRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');
    }

    public function testTranscriptionOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Service overloaded']], 503)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');
    }

    public function testTranscriptionHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid audio']], 400)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');
    }

    public function testTranscriptionCanBeFakedForTheOpenaiCompatibleProvider(): void
    {
        Transcription::fake(['Faked transcript']);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        $this->assertSame('Faked transcript', $response->text);
    }

    public function testTranscriptionReportsTheWhisperAudioDuration(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!', 'duration' => 9.25])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        $this->assertSame(9.25, $response->usage->audioSeconds);
    }

    public function testTranscriptionPrefersTheUsageSecondsOverTheWhisperDuration(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!', 'duration' => 9.25, 'usage' => ['seconds' => 3.5]])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openai-compatible');

        $this->assertSame(3.5, $response->usage->audioSeconds);
    }
}
