<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\ElevenLabs;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AudioTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.eleven.key', 'test-key');
    }

    public function testAudioRequestIncludesModelIdTextAndResolvesDefaultFemaleVoice(): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model_id'] === 'eleven_multilingual_v2'
                && $body['text'] === 'Hello world'
                && $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX';
        });
    }

    public function testAudioRequestResolvesDefaultMaleVoiceAlias(): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello')->male()->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/onwK4e9ZLuTAKqWW03F9');
    }

    public function testAudioRequestPassesCustomVoiceIdThroughUnchanged(): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/my-custom-voice-id');
    }

    public function testAudioRequestSendsXiApiKeyHeader(): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('xi-api-key', 'test-key'));
    }

    public function testAudioResponseIsBase64EncodedWithAudioMpegMimeType(): void
    {
        Http::fake(['*' => Http::response('raw-audio-bytes')]);

        $response = Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        $this->assertSame(base64_encode('raw-audio-bytes'), $response->audio);
        $this->assertSame('audio/mpeg', $response->mimeType());
        $this->assertSame('eleven', $response->meta->provider);
        $this->assertSame('eleven_multilingual_v2', $response->meta->model);
    }

    public function testAudioUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'eleven');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model_id'] === 'eleven_multilingual_v2');
    }

    #[DataProvider('loggingOptions')]
    public function testAudioSendsQueryStringProviderOptionsAsQueryParametersInsteadOfInTheBody(bool $enableLogging, string $expected): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello')
            ->withProviderOptions([
                'output_format' => 'wav_44100',
                'enable_logging' => $enableLogging,
                'optimize_streaming_latency' => 0,
            ])
            ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(function (Request $request) use ($expected): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX?')
                && $query === ['output_format' => 'wav_44100', 'enable_logging' => $expected, 'optimize_streaming_latency' => '0']
                && ! Arr::hasAny(json_decode($request->body(), true), ['output_format', 'enable_logging', 'optimize_streaming_latency']);
        });
    }

    /**
     * Provide logging options and their query values.
     */
    public static function loggingOptions(): array
    {
        return [[false, 'false'], [true, 'true']];
    }

    public function testAudioKeepsBodyProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => $this->fakeElevenAudioResponse()]);

        Audio::of('Hello')
            ->withProviderOptions([
                'output_format' => 'mp3_44100_192',
                'seed' => 42,
                'voice_settings' => ['stability' => 0.5],
                'model_id' => 'hijacked',
            ])
            ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['seed'] === 42
                && $body['voice_settings'] === ['stability' => 0.5]
                && $body['model_id'] === 'eleven_multilingual_v2'
                && $body['text'] === 'Hello';
        });
    }

    public function testAudioResponseMimeTypeFollowsTheReturnedContentType(): void
    {
        Http::fake(['*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/wav'])]);

        $response = Audio::of('Hello')
            ->withProviderOptions(['output_format' => 'wav_44100'])
            ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        $this->assertSame('audio/wav', $response->mimeType());
    }

    public function testAudioThrowsWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

        Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
    }

    public function testAudioRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

        Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
    }

    public function testAudioOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => 'service unavailable'], 503)]);

        Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
    }

    /**
     * Create a successful audio response.
     */
    protected function fakeElevenAudioResponse(): PromiseInterface
    {
        return Http::response('fake-audio-bytes');
    }
}
