<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use RuntimeException;

class AudioTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testAudioRequestIncludesModelInputVoiceIdAndResolvesDefaultFemaleVoice(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse()]);

        Audio::of('Hello world')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.mistral.ai/v1/audio/speech'
                && $body['model'] === 'voxtral-mini-tts-2603'
                && $body['input'] === 'Hello world'
                && $body['voice_id'] === 'gb_jane_neutral'
                && $body['response_format'] === 'mp3';
        });
    }

    public function testAudioRequestResolvesDefaultMaleVoiceAlias(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse()]);

        Audio::of('Hello')->male()->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice_id'] === 'en_paul_neutral');
    }

    public function testAudioRequestPassesCustomVoiceIdThroughUnchanged(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse()]);

        Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice_id'] === 'my-custom-voice-id');
    }

    public function testAudioRequestOmitsInstructionsSinceTheMistralApiDoesNotAcceptThem(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse()]);

        Audio::of('Hello')->instructions('Speak warmly')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'Speak warmly')
            && ! array_key_exists('instructions', json_decode($request->body(), true)));
    }

    public function testAudioRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testAudioResponsePassesBase64AudioDataThroughWithAudioMpegMimeType(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse(base64_encode('raw-audio-bytes'))]);

        $response = Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

        $this->assertSame(base64_encode('raw-audio-bytes'), $response->audio);
        $this->assertSame('audio/mpeg', $response->mimeType());
        $this->assertSame('mistral', $response->meta->provider);
        $this->assertSame('voxtral-mini-tts-2603', $response->meta->model);
    }

    public function testAudioUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeMistralAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'voxtral-mini-tts-2603');
    }

    public function testAudioThrowsWhenTheResponseContainsNoAudioData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No audio data received from Mistral API.');
        Http::fake(['*' => Http::response(['audio_data' => null])]);

        Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
    }

    public function testAudioThrowsWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
    }

    public function testAudioRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'rate limit exceeded'], 429)]);

        Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
    }

    public function testAudioOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'service unavailable'], 503)]);

        Audio::of('Hello')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');
    }

    public function testAudioGatewayIsSharedWithTheOtherMistralGateways(): void
    {
        $provider = Ai::audioProvider('mistral');

        $this->assertSame($provider->audioGateway(), $provider->transcriptionGateway());
        $this->assertSame($provider->audioGateway(), $provider->embeddingGateway());
    }

    /**
     * Create an audio response.
     */
    protected function fakeMistralAudioResponse(?string $audioData = null): PromiseInterface
    {
        return Http::response([
            'audio_data' => $audioData ?? base64_encode('fake-audio-bytes'),
        ]);
    }
}
