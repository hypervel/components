<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Jobs\GenerateTranscription;
use Hypervel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Hypervel\Ai\Prompts\QueuedTranscriptionPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class TranscriptionProviderOptionsTest extends TestCase
{
    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set([
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.mistral.key' => 'test-key',
        ]);
    }

    public function testFlatProviderOptionsAreSentOnTheTranscriptionRequest(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(['prompt' => 'Hypervel Forge and Vapor'])
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Hypervel Forge and Vapor'));
    }

    public function testClosureResolverReceivesTheResolvedProviderAndAppliesPerProviderOptions(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]]),
            'api.mistral.ai/*' => Http::response(['text' => 'Hello', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
        ]);

        $seen = [];

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(function (Provider $provider) use (&$seen): array {
                $seen[] = $provider->driver();

                return $provider->driver() === 'openai'
                    ? ['prompt' => 'OpenAI hint']
                    : ['context_bias' => 'Mistral,hint'];
            })
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

        $this->assertSame(['openai'], $seen);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'OpenAI hint'));
    }

    public function testClosureProviderOptionsAreNotRecordedOnTheQueuedPromptFake(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')
            ->withProviderOptions(fn (Provider $provider): array => ['prompt' => 'hint'])
            ->queue(provider: 'openai');

        Transcription::assertQueued(
            fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->providerOptions === [],
        );
    }

    public function testClosureProviderOptionsSurviveQueueSerializationRoundTrip(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]]),
        ]);

        $pending = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(fn (Provider $provider): array => ['prompt' => 'Serialized hint']);

        $job = new GenerateTranscription($pending, 'openai', 'gpt-4o-transcribe');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(PendingTranscriptionGeneration::class, $restored->pendingTranscription);

        $restored->handle();

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Serialized hint'));
    }

    public function testClosureResolverReturningNullIsTreatedAsNoOptions(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['text' => 'Hello', 'usage' => ['input_tokens' => 1, 'total_tokens' => 2]]),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withProviderOptions(fn (): null => null)
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'prompt'));
    }
}
