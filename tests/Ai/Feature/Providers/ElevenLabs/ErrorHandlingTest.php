<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\ElevenLabs;

use Hypervel\Ai\Audio;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class ErrorHandlingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.eleven.key', 'test-key');
    }

    public function testAudioRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
        ]);

        Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
    }

    public function testAudioOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'Service overloaded'], 503),
        ]);

        Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
    }

    public function testAudioHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
    }

    public function testTranscriptionRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'eleven');
    }

    public function testTranscriptionOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'Service overloaded'], 503),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'eleven');
    }

    public function testTranscriptionHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'eleven');
    }
}
