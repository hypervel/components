<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

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
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq', [
            'driver' => 'groq',
            'key' => 'test-key',
        ]);
    }

    public function testTranscriptionPostsAudioToTheTranscriptionsEndpointAsMultipart(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
    }

    public function testTranscriptionUsesTheDefaultWhisperModelWhenNoneIsConfigured(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'whisper-large-v3-turbo'));
    }

    public function testTranscriptionUsesTheConfiguredDefaultModelWhenSet(): void
    {
        config(['ai.providers.groq' => [
            'driver' => 'groq',
            'key' => 'test-key',
            'models' => ['transcription' => ['default' => 'custom-whisper-model']],
        ]]);

        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'custom-whisper-model'));
    }

    public function testTranscriptionSendsLanguageWhenProvided(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Bonjour'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('fr')
            ->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'name="language"'));
    }

    public function testTranscriptionOmitsLanguageWhenNotProvided(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language"'));
    }

    public function testTranscriptionDiarizeThrowsALogicExceptionWithoutSendingARequest(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        try {
            Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->diarize()->generate(provider: 'groq');
            $this->fail('Expected unsupported diarization to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('does not support diarized transcription', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function testTranscriptionResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        $this->assertSame('Hello, world!', $response->text);
        $this->assertCount(0, $response->segments);
        $this->assertSame('groq', $response->meta->provider);
        $this->assertSame('whisper-large-v3-turbo', $response->meta->model);
    }

    public function testTranscriptionSegmentsAreParsedWhenAVerboseResponseFormatIsRequested(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello, world!',
            'segments' => [
                ['text' => 'Hello,', 'start' => 0.0, 'end' => 1.5],
                ['text' => 'world!', 'start' => 1.5, 'end' => 2.75],
            ],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['response_format' => 'verbose_json'])
            ->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'verbose_json')
            && substr_count($request->body(), 'name="response_format"') === 1);

        $this->assertCount(2, $response->segments);
        $this->assertSame('Hello,', $response->segments->first()->text);
        $this->assertSame(0.0, $response->segments->first()->startSeconds);
        $this->assertSame(2.75, $response->segments->last()->endSeconds);
    }

    public function testTranscriptionSendsTheBearerToken(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hi'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testTranscriptionRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
    }

    public function testTranscriptionOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Service overloaded']], 503)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
    }

    public function testTranscriptionHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid audio']], 400)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
    }

    public function testTranscriptionCanBeFakedForTheGroqProvider(): void
    {
        Transcription::fake(['Faked transcript']);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        $this->assertSame('Faked transcript', $response->text);
    }

    public function testTranscriptionRequestsVerboseJsonSoTheAudioDurationIsReturned(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!', 'duration' => 8.47])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'verbose_json'));

        $this->assertSame(8.47, $response->usage->audioSeconds);
    }

    public function testTranscriptionResponseFormatCanBeOverriddenWithAProviderOption(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['response_format' => 'json'])
            ->generate(provider: 'groq');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'verbose_json'));
    }

    public function testTranscriptionLeavesTheAudioDurationNullWhenNotReturned(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

        $this->assertNull($response->usage->audioSeconds);
    }
}
