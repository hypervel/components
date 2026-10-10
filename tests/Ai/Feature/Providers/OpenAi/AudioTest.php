<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class AudioTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testAudioRequestIncludesModelInputVoiceResponseFormatAndSpeed(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello world')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-4o-mini-tts'
                && $body['input'] === 'Hello world'
                && $body['voice'] === 'alloy'
                && $body['response_format'] === 'mp3'
                && $body['speed'] == 1.0
                && $request->url() === 'https://api.openai.com/v1/audio/speech';
        });
    }

    public function testAudioRequestResolvesDefaultFemaleVoiceToAlloy(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello')->female()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'alloy');
    }

    public function testAudioRequestResolvesDefaultMaleVoiceToAsh(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello')->male()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'ash');
    }

    public function testAudioRequestPassesCustomVoiceIdThroughUnchanged(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello')->voice('nova')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'nova');
    }

    public function testAudioRequestIncludesInstructionsWhenProvided(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['instructions'] === 'Speak slowly');
    }

    public function testAudioResponseIsBase64EncodedWithAudioMpegMimeType(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        $response = Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        $this->assertSame(base64_encode('fake-audio-bytes'), $response->audio);
        $this->assertSame('audio/mpeg', $response->mimeType());
        $this->assertSame('openai', $response->meta->provider);
        $this->assertSame('gpt-4o-mini-tts', $response->meta->model);
    }

    public function testAudioUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello')->generate(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gpt-4o-mini-tts');
    }

    public function testAudioRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
        ], 429)]);

        Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
    }

    public function testAudioHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Bad request'],
        ], 400)]);

        Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
    }

    public function testAudioRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeOpenAiAudioResponse()]);
        Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    /**
     * Create an audio response.
     */
    private function fakeOpenAiAudioResponse(): PromiseInterface
    {
        return Http::response('fake-audio-bytes');
    }
}
