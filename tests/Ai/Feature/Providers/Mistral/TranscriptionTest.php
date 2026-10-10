<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class TranscriptionTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testTranscriptionRequestPostsToCorrectEndpoint(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
    }

    public function testTranscriptionResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse('Hello, world!')]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        $this->assertSame('Hello, world!', $response->text);
        $this->assertSame('mistral', $response->meta->provider);
    }

    public function testTranscriptionIncludesModelInRequest(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'voxtral-mini-2602'));
    }

    public function testTranscriptionSendsLanguageWhenProvided(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('en')
            ->generate(provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'language')
            && str_contains($request->body(), 'en'));
    }

    public function testTranscriptionSendsContextBiasFromProviderOptions(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['context_bias' => 'Hypervel,Forge,Vapor'])
            ->generate(provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'context_bias')
            && str_contains($request->body(), 'Hypervel,Forge,Vapor'));
    }

    public function testTranscriptionSendsContextBiasArrayAsRepeatedParts(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['context_bias' => ['Hypervel', 'Forge', 'Vapor']])
            ->generate(provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return substr_count($body, 'name="context_bias"') === 3
                && str_contains($body, 'Hypervel')
                && str_contains($body, 'Forge')
                && str_contains($body, 'Vapor');
        });
    }

    public function testTranscriptionSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testTranscriptionUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello',
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
    }

    public function testTranscriptionOmitsLanguageAndSendsDiarizeFlagWhenDiarizing(): void
    {
        Http::fake(['*' => $this->fakeTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('en')
            ->diarize()
            ->generate(provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, 'diarize')
                && str_contains($body, 'name="timestamp_granularities"')
                && ! str_contains($body, 'timestamp_granularities[')
                && str_contains($body, 'segment')
                && ! str_contains($body, 'language');
        });
    }

    public function testTranscriptionResponseSegmentsAreParsedWhenDiarizing(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello world',
            'segments' => [
                ['text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
                ['text' => 'world', 'speaker_id' => 'speaker_1', 'start' => 0.6, 'end' => 1.0],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->diarize()
            ->generate(provider: 'mistral');

        $this->assertCount(2, $response->segments);
        $this->assertSame('Hello', $response->segments[0]->text);
        $this->assertSame('speaker_0', $response->segments[0]->speaker);
        $this->assertSame(0.0, $response->segments[0]->startSeconds);
        $this->assertSame('world', $response->segments[1]->text);
        $this->assertSame('speaker_1', $response->segments[1]->speaker);
    }

    public function testTranscriptionThrowsWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');
    }

    /**
     * Create a transcription response.
     */
    protected function fakeTranscriptionResponse(string $text = 'Hello, world!'): PromiseInterface
    {
        return Http::response([
            'text' => $text,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }

    public function testTranscriptionReportsThePromptAudioSeconds(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello, world!',
            'usage' => ['prompt_audio_seconds' => 203, 'prompt_tokens' => 12, 'completion_tokens' => 8],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'mistral');

        $this->assertSame(203.0, $response->usage->audioSeconds);
        $this->assertSame(12, $response->usage->inputTokens);
    }
}
