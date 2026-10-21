<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Audio;
use Hypervel\Ai\Jobs\GenerateAudio;
use Hypervel\Ai\Prompts\QueuedAudioPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class AudioProviderOptionsTest extends TestCase
{
    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testProviderOptionsMayNotOverrideTheCoreAudioRequestPayload(): void
    {
        Http::fake(['*' => Http::response('fake-audio-bytes')]);

        Audio::of('Hello world')
            ->withProviderOptions(['model' => 'hijacked', 'speed' => 0.8])
            ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-4o-mini-tts' && $body['speed'] === 0.8;
        });
    }

    public function testClosureProviderOptionsReceiveTheResolvedAudioProvider(): void
    {
        Http::fake(['*' => Http::response('fake-audio-bytes')]);

        $seen = [];

        Audio::of('Hello world')
            ->withProviderOptions(function (Provider $provider) use (&$seen): array {
                $seen[] = $provider->driver();

                return ['speed' => 0.8];
            })
            ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        $this->assertSame(['openai'], $seen);

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['speed'] === 0.8);
    }

    public function testFlatProviderOptionsAreRecordedOnTheQueuedAudioPromptFake(): void
    {
        Audio::fake();

        Audio::of('Hello world')
            ->withProviderOptions(['speed' => 0.8])
            ->queue(provider: 'openai');

        Audio::assertQueued(
            fn (QueuedAudioPrompt $prompt): bool => $prompt->providerOptions === ['speed' => 0.8],
        );
    }

    public function testFalsyProviderOptionsAreNotDroppedFromTheAudioRequest(): void
    {
        Http::fake(['*' => Http::response('fake-audio-bytes')]);

        Audio::of('Hello world')
            ->withProviderOptions(['stream' => false])
            ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return array_key_exists('stream', $body) && $body['stream'] === false;
        });
    }

    public function testClosureProviderOptionsSurviveQueueSerializationRoundTrip(): void
    {
        Http::fake(['*' => Http::response('fake-audio-bytes')]);

        $job = new GenerateAudio(
            Audio::of('Hello world')->withProviderOptions(fn (Provider $provider): array => ['speed' => 0.8]),
            'openai',
            'gpt-4o-mini-tts',
        );

        unserialize(serialize($job))->handle();

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['speed'] === 0.8);
    }
}
