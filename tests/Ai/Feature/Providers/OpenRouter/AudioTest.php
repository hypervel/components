<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AudioTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    #[DataProvider('speechInputs')]
    public function testAudioRequestIncludesModelInputVoiceResponseFormatAndSpeed(string $text): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of($text)->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(function (Request $request) use ($text): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'openai/gpt-4o-mini-tts-2025-12-15'
                && ($body['input'] ?? null) === $text
                && $body['voice'] === 'alloy'
                && $body['response_format'] === 'mp3'
                && $body['speed'] === 1
                && $request->url() === 'https://openrouter.ai/api/v1/audio/speech';
        });
    }

    /**
     * Provide ordinary and zero-valued speech text.
     */
    public static function speechInputs(): array
    {
        return [['Hello world'], ['0']];
    }

    public function testAudioRequestResolvesDefaultFemaleVoiceToAlloy(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->female()->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'alloy');
    }

    public function testAudioRequestResolvesDefaultMaleVoiceToAsh(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->male()->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'ash');
    }

    public function testAudioRequestPassesCustomVoiceIdThroughUnchanged(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->voice('shimmer')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'shimmer');
    }

    public function testAudioRequestIncludesInstructionsWhenProvided(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['instructions'] === 'Speak slowly');
    }

    public function testAudioRequestOmitsInstructionsWhenNotProvided(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('instructions', json_decode($request->body(), true)));
    }

    public function testAudioUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'google/gemini-3.8-flash-lite-tts');
    }

    public function testAudioRequestToGeminiTtsModelUsesPcmResponseFormatAndPcmMime(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        $response = Audio::of('Hello')->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['response_format'] === 'pcm');
        $this->assertSame('audio/pcm', $response->mimeType());
    }

    public function testAudioRequestToGeminiTtsModelResolvesDefaultFemaleVoiceToKore(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->female()->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'Kore');
    }

    public function testAudioRequestToGeminiTtsModelResolvesDefaultMaleVoiceToPuck(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->male()->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['voice'] === 'Puck');
    }

    public function testAudioResponseIsBase64EncodedWithAudioMpegMimeType(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        $response = Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        $this->assertSame(base64_encode('fake-audio-bytes'), $response->audio);
        $this->assertSame('audio/mpeg', $response->mimeType());
        $this->assertSame('openrouter', $response->meta->provider);
        $this->assertSame('openai/gpt-4o-mini-tts-2025-12-15', $response->meta->model);
    }

    public function testAudioRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testAudioRequestSendsOpenrouterAttributionHeadersWhenConfigured(): void
    {
        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
            'http_referer' => 'https://example.test',
            'x_title' => 'Example App',
        ]]);

        Http::fake(['*' => $this->fakeOpenRouterAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('HTTP-Referer', 'https://example.test')
            && $request->hasHeader('X-OpenRouter-Title', 'Example App'));
    }

    public function testAudioRateLimitResponseThrowsRateLimitedException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limit exceeded', 'code' => 429]], 429)]);

        $this->expectException(RateLimitedException::class);
        Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
    }

    public function testAudioOverloadedResponseThrowsProviderOverloadedException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Service overloaded', 'code' => 503]], 503)]);

        $this->expectException(ProviderOverloadedException::class);
        Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
    }

    public function testAudioHttpErrorResponseThrowsRequestException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Bad request', 'code' => 400]], 400)]);

        $this->expectException(RequestException::class);
        Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
    }

    /**
     * Create an audio response.
     */
    protected function fakeOpenRouterAudioResponse(): PromiseInterface
    {
        return Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']);
    }
}
