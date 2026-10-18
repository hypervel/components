<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\ElevenLabs;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Transcription;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class BaseUrlTest extends TestCase
{
    /**
     * Create a successful audio response.
     */
    protected function fakeElevenBaseUrlAudioResponse(): PromiseInterface
    {
        return Http::response('fake-audio-bytes');
    }

    /**
     * Create a successful transcription response.
     */
    protected function fakeElevenBaseUrlTranscriptionResponse(): PromiseInterface
    {
        return Http::response(['text' => 'Hello world']);
    }

    public function testElevenLabsAudioRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.eleven' => [
            ...config('ai.providers.eleven'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v1',
        ]]);

        Http::fake(['*' => $this->fakeElevenBaseUrlAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'http://localhost:8080/v1/text-to-speech/'));
    }

    public function testElevenLabsTranscriptionRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.eleven' => [
            ...config('ai.providers.eleven'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v1',
        ]]);

        Http::fake(['*' => $this->fakeElevenBaseUrlTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'eleven', model: 'scribe_v2');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/speech-to-text');
    }

    public function testElevenLabsRequestsFallBackToTheDefaultBaseUrl(): void
    {
        config(['ai.providers.eleven' => array_diff_key(
            [...config('ai.providers.eleven'), 'key' => 'test-key'],
            ['url' => null],
        )]);

        Http::fake(['*' => $this->fakeElevenBaseUrlAudioResponse()]);

        Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.elevenlabs.io/v1/text-to-speech/'));
    }
}
