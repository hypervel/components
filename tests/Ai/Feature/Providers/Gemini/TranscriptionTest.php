<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

/**
 * Create a transcription interaction response.
 */
function fakeGeminiTranscriptionInteraction(string $text, array $usage): PromiseInterface
{
    return Http::response([
        'id' => 'int_transcription',
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [['type' => 'text', 'text' => $text]],
        ]],
        'usage' => $usage,
    ]);
}

/**
 * Create a plain transcription response.
 */
function fakeGeminiTranscriptionResponse(): PromiseInterface
{
    return fakeGeminiTranscriptionInteraction('Hello world', [
        'total_input_tokens' => 10,
        'total_output_tokens' => 5,
        'total_tokens' => 15,
    ]);
}

/**
 * Create a word-level transcription response.
 */
function fakeGeminiWordTranscriptionResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'int_transcription',
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [[
                'type' => 'text',
                'text' => 'Hello there. How are you?',
                'annotations' => [
                    ['type' => 'word_info', 'text' => 'Hello', 'speaker' => 'spk:0', 'start_offset' => '0.100s', 'end_offset' => '0.400s'],
                    ['type' => 'word_info', 'text' => 'there.', 'speaker' => 'spk:0', 'start_offset' => '0.400s', 'end_offset' => '1s'],
                    ['type' => 'word_info', 'text' => 'How', 'speaker' => 'spk:1', 'start_offset' => '1.200s', 'end_offset' => '1.400s'],
                    ['type' => 'word_info', 'text' => 'you?', 'speaker' => 'spk:1', 'start_offset' => '1.400s', 'end_offset' => '2s'],
                ],
            ]],
        ]],
        'usage' => ['total_input_tokens' => 55, 'total_output_tokens' => 8, 'total_tokens' => 63],
    ]);
}

/**
 * Create a diarized transcription response.
 */
function fakeGeminiDiarizedTranscriptionResponse(?array $segments = null): PromiseInterface
{
    $segments ??= [
        ['text' => 'Hello', 'start_time' => '0:00', 'end_time' => '0:02'],
        ['text' => 'world', 'start_time' => '0:02', 'end_time' => '0:04'],
    ];

    return fakeGeminiTranscriptionInteraction(
        json_encode(['transcript' => 'Hello world', 'segments' => $segments]),
        ['total_input_tokens' => 42, 'total_output_tokens' => 20, 'total_tokens' => 62],
    );
}

class TranscriptionTest extends TestCase
{
    /**
     * Configure the test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('ai.providers.gemini.key', 'test-key');
    }

    public function testTranscriptionRequestSendsAudioAsAnAudioBlockWithCorrectMimeType(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertStringEndsWith('/interactions', sentRequest()->url());
        $this->assertSame('gemini-3.7-flash', sentRequest()->data()['model']);
        $this->assertSame([
            'type' => 'audio',
            'mime_type' => 'audio/mp3',
            'data' => base64_encode('fake-audio'),
        ], sentRequest()->data()['input'][1]);
    }

    public function testTranscriptionRequestIncludesLanguageInPromptWhenSpecified(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))
            ->language('fr')
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertStringContainsString('fr', sentRequest()->data()['input'][0]['text']);
    }

    public function testTranscriptionResponseReturnsTextWithCorrectMeta(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        $response = Transcription::of(base64_encode('fake-audio'))
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertSame('Hello world', $response->text);
        $this->assertCount(0, $response->segments);
        $this->assertSame('gemini', $response->meta->provider);
        $this->assertSame('gemini-3.7-flash', $response->meta->model);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testTranscriptionUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))->generate(provider: 'gemini');

        $this->assertSame('gemini-3.5-transcribe', sentRequest()->data()['model']);
    }

    public function testATranscribeModelIsConfiguredInsteadOfPrompted(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))
            ->language('en-US')
            ->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

        $this->assertCount(1, sentRequest()->data()['input']);
        $this->assertSame('audio', sentRequest()->data()['input'][0]['type']);
        $this->assertSame(['language_codes' => ['en-US']], sentRequest()->data()['generation_config']['transcription_config']);
        $this->assertFalse(sentRequest()->data()['store']);
        $this->assertArrayNotHasKey('response_format', sentRequest()->data());
    }

    public function testATranscribeRequestWithoutALanguageOmitsTheGenerationConfig(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

        $this->assertArrayNotHasKey('generation_config', sentRequest()->data());
    }

    public function testADiarizedTranscribeRequestAsksForSpeakerLabelsAndWordTimestamps(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiWordTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

        $this->assertSame([
            'type' => 'verbatim',
            'diarization_mode' => 'speaker',
            'timestamp_granularities' => ['word'],
        ], sentRequest()->data()['generation_config']['transcription_config']['mode']);
    }

    public function testADiarizedTranscribeResponseGroupsConsecutiveWordsIntoSpeakerSegments(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiWordTranscriptionResponse(),
        ]);

        $response = Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'gemini', model: 'gemini-3.5-transcribe');

        $this->assertSame('Hello there. How are you?', $response->text);
        $this->assertCount(2, $response->segments);
        $this->assertSame('Hello there.', $response->segments[0]->text);
        $this->assertSame('spk:0', $response->segments[0]->speaker);
        $this->assertSame(0.1, $response->segments[0]->startSeconds);
        $this->assertSame(1.0, $response->segments[0]->endSeconds);
        $this->assertSame('How you?', $response->segments[1]->text);
        $this->assertSame('spk:1', $response->segments[1]->speaker);
        $this->assertSame(1.2, $response->segments[1]->startSeconds);
        $this->assertSame(2.0, $response->segments[1]->endSeconds);
        $this->assertSame(55, $response->usage->inputTokens);
    }

    public function testAGeneralPurposeModelStillTranscribesThroughAPrompt(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertStringContainsString('Transcribe this audio', sentRequest()->data()['input'][0]['text']);
        $this->assertArrayNotHasKey('generation_config', sentRequest()->data());
    }

    public function testDiarizedTranscriptionRequestSendsAJsonResponseFormat(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
        ]);

        Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertSame('text', sentRequest()->data()['response_format']['type']);
        $this->assertSame('application/json', sentRequest()->data()['response_format']['mime_type']);
        $this->assertArrayHasKey('segments', sentRequest()->data()['response_format']['schema']['properties']);
        $this->assertStringContainsString('MM:SS or HH:MM:SS', sentRequest()->data()['input'][0]['text']);
    }

    public function testDiarizedTranscriptionResponseReturnsTextAndSegments(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
        ]);

        $response = Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertSame('Hello world', $response->text);
        $this->assertCount(2, $response->segments);
        $this->assertSame('Hello', $response->segments[0]->text);
        $this->assertSame(0.0, $response->segments[0]->startSeconds);
        $this->assertSame(2.0, $response->segments[0]->endSeconds);
        $this->assertSame('world', $response->segments[1]->text);
        $this->assertSame(2.0, $response->segments[1]->startSeconds);
        $this->assertSame(4.0, $response->segments[1]->endSeconds);
        $this->assertSame(42, $response->usage->inputTokens);
        $this->assertSame(20, $response->usage->outputTokens);
    }

    public function testDiarizedTranscriptionParsesSrtAndHourTimestampFormats(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse([
                ['text' => 'Hello', 'start_time' => '00:00:01,500', 'end_time' => '00:01:02,250'],
                ['text' => 'world', 'start_time' => '01:02:03.750', 'end_time' => '3724.25'],
            ]),
        ]);

        $response = Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

        $this->assertSame(1.5, $response->segments[0]->startSeconds);
        $this->assertSame(62.25, $response->segments[0]->endSeconds);
        $this->assertSame(3723.75, $response->segments[1]->startSeconds);
        $this->assertSame(3724.25, $response->segments[1]->endSeconds);
    }

    #[DataProvider('transcriptionModes')]
    public function testFailedInteractionPreservesProviderError(string $model, bool $diarize): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failed',
            'errors' => [['code' => 'overloaded', 'message' => 'Server overloaded']],
        ])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error: [overloaded] Server overloaded');

        Transcription::of(base64_encode('fake-audio'))->diarize($diarize)->generate(provider: 'gemini', model: $model);
    }

    /**
     * Provide config and prompt transcription modes.
     */
    public static function transcriptionModes(): array
    {
        return [
            'config' => ['gemini-3.5-transcribe', false],
            'prompt' => ['gemini-3.7-flash', false],
            'diarized prompt' => ['gemini-3.7-flash', true],
        ];
    }
}
