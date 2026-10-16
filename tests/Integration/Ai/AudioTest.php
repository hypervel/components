<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Audio;
use Hypervel\Ai\Events\AudioGenerated;
use Hypervel\Ai\Events\GeneratingAudio;
use Hypervel\Ai\Files;
use Hypervel\Ai\Transcription;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class AudioTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'textToSpeech')]
    public function testAudioCanBeGenerated(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $response = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

        $this->assertSame($provider, $response->meta->provider);
        $this->assertNotEmpty($response->audio);
        $this->assertNotNull($response->mimeType());

        Event::assertDispatched(GeneratingAudio::class, fn (GeneratingAudio $event): bool => $event->prompt->timeout === 30);
        Event::assertDispatched(AudioGenerated::class, fn (AudioGenerated $event): bool => $event->prompt->timeout === 30);
    }

    #[DataProviderExternal(Providers::class, 'transcription')]
    public function testTranscriptionCanBeGeneratedFromLocalPath(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $transcription = Files\Audio::fromPath(__DIR__ . '/Fixtures/audio.mp3')
            ->transcription()
            ->generate(provider: $provider);

        $this->assertStringContainsString('how are you today', strtolower((string) $transcription));
    }

    #[DataProviderExternal(Providers::class, 'textToSpeech')]
    public function testAudioCanBeTranscribedAfterGeneration(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $audio = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

        $transcription = Transcription::fromBase64($audio->audio, $audio->mimeType())
            ->generate(provider: $provider);

        $this->assertStringContainsString('how are you today', strtolower((string) $transcription));
    }

    #[DataProviderExternal(Providers::class, 'diarization')]
    public function testTranscriptionCanBeDiarized(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $audio = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

        $transcription = Transcription::fromBase64($audio->audio, $audio->mimeType())
            ->diarize()
            ->generate(provider: $provider);

        $this->assertStringContainsString('how are you today', strtolower((string) $transcription));
        $this->assertNotEmpty($transcription->segments);
    }
}
