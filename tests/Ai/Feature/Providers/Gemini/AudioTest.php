<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use RuntimeException;

use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class AudioTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.gemini.key', 'test-key');
    }

    /**
     * Create an audio response with optional usage and format details.
     */
    protected function fakeGeminiAudioResponse(string $pcm = "\x00\x00", array $usage = [], array $extra = []): PromiseInterface
    {
        return Http::response(array_filter([
            'id' => 'int_audio',
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [array_merge([
                    'type' => 'audio',
                    'data' => base64_encode($pcm),
                    'mime_type' => 'audio/pcm',
                ], $extra)],
            ]],
            'usage' => $usage ?: null,
        ]));
    }

    public function testAudioRequestIncludesModelPromptTextAndVoiceName(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        Audio::of('Hello world')
            ->voice('Kore')
            ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertStringEndsWith('/interactions', sentRequest()->url());
        $this->assertSame('gemini-3.1-flash-tts-preview', sentRequest()->data()['model']);
        $this->assertSame('Hello world', sentRequest()->data()['input']);
        $this->assertSame(['type' => 'audio'], sentRequest()->data()['response_format']);
        $this->assertFalse(sentRequest()->data()['store']);
        $this->assertSame([['voice' => 'Kore']], sentRequest()->data()['generation_config']['speech_config']);
    }

    public function testAudioRequestResolvesDefaultVoiceAliases(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');
        Audio::of('Hello world')->male()->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        Http::assertSent(fn (Request $request): bool => $request->data()['generation_config']['speech_config'][0]['voice'] === 'Kore');
        Http::assertSent(fn (Request $request): bool => $request->data()['generation_config']['speech_config'][0]['voice'] === 'Puck');
    }

    public function testAudioInstructionsArePrependedToThePromptInsteadOfSentInSpeechConfig(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        Audio::of('Have a wonderful day!')
            ->voice('Kore')
            ->instructions('Say cheerfully:')
            ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertSame("Say cheerfully:\n\nHave a wonderful day!", sentRequest()->data()['input']);
        $this->assertArrayNotHasKey('instructions', sentRequest()->data()['generation_config']['speech_config'][0]);
    }

    public function testAudioResponseIsWrappedAsWavWithCorrectMeta(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse("\x01\x00\x02\x00"),
        ]);

        $response = Audio::of('Hello world')
            ->voice('Kore')
            ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertSame('audio/wav', $response->mimeType());
        $this->assertSame('RIFF', substr($response->content(), 0, 4));
        $this->assertSame('WAVE', substr($response->content(), 8, 4));
        $this->assertSame('gemini', $response->meta->provider);
        $this->assertSame('gemini-3.1-flash-tts-preview', $response->meta->model);
    }

    public function testAudioResponseHonorsTheSampleRateAndChannelsGeminiReports(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(
                "\x01\x00\x02\x00",
                extra: ['sample_rate' => 48000, 'channels' => 2],
            ),
        ]);

        $response = Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $header = $response->content();

        $this->assertSame(48000, unpack('V', substr($header, 24, 4))[1]);
        $this->assertSame(2, unpack('v', substr($header, 22, 2))[1]);
    }

    public function testAudioRequestPassesCustomVoiceNameThroughUnchanged(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        Audio::of('Hello world')
            ->voice('my-custom-voice')
            ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertSame('my-custom-voice', sentRequest()->data()['generation_config']['speech_config'][0]['voice']);
    }

    public function testAudioUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        Audio::of('Hello world')->voice('Kore')->generate(provider: 'gemini');

        $this->assertSame('gemini-3.8-flash-lite-tts', sentRequest()->data()['model']);
    }

    public function testNestedGenerationConfigProviderOptionsAreMergedBeneathTheCoreConfig(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        Audio::of('Hello world')
            ->voice('Kore')
            ->withProviderOptions(['generation_config' => [
                'temperature' => 0.1,
                'speech_config' => [['language_code' => 'en-US']],
            ]])
            ->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertSame(0.1, sentRequest()->data()['generation_config']['temperature']);
        $this->assertSame('en-US', sentRequest()->data()['generation_config']['speech_config'][0]['language_code']);
        $this->assertSame('Kore', sentRequest()->data()['generation_config']['speech_config'][0]['voice']);
    }

    public function testAudioResponseReportsTheUsageReturnedByGemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse("\x00\x00", [
                'total_input_tokens' => 9,
                'total_output_tokens' => 480,
                'total_tokens' => 489,
            ]),
        ]);

        $response = Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertSame(9, $response->usage->inputTokens);
        $this->assertSame(480, $response->usage->outputTokens);
    }

    public function testAudioResponseDefaultsToZeroUsageWhenGeminiOmitsTheUsage(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeGeminiAudioResponse(),
        ]);

        $response = Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');

        $this->assertSame(0, $response->usage->totalTokens());
    }

    public function testAResponseWithoutAnAudioBlockFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No audio data received from Gemini API.');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'id' => 'int_audio',
                'status' => 'completed',
                'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'No audio.']]]],
            ]),
        ]);

        Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-3.1-flash-tts-preview');
    }

    public function testFailedInteractionPreservesTheProviderError(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failed',
            'errors' => [['code' => 'internal', 'message' => 'The model stopped responding.']],
        ])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error: [internal] The model stopped responding.');

        Audio::of('Hello')->generate(provider: 'gemini');
    }
}
