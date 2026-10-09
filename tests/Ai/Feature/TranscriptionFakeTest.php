<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Exception;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\GenerateTranscription;
use Hypervel\Ai\Prompts\QueuedTranscriptionPrompt;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Ai\Transcription;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class TranscriptionFakeTest extends TestCase
{
    /**
     * Clear serialized callback results.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['transcriptionResponse']);

        parent::tearDown();
    }

    public function testTranscriptionRejectsEmptyAudioString(): void
    {
        Transcription::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 audio content cannot be empty.');
        Transcription::of('')->generate();
    }

    public function testTranscriptionRejectsEmptyBase64Audio(): void
    {
        Transcription::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 audio content cannot be empty.');
        Transcription::fromBase64('')->generate();
    }

    public function testTranscriptionsCanBeFaked(): void
    {
        Transcription::fake([
            'First transcription',
            fn (TranscriptionPrompt $prompt): string => 'Second transcription',
            new TranscriptionResponse(
                'Third transcription',
                new Collection([new TranscriptionSegment('Third transcription', 'Speaker 1', 0.0, 1.0)]),
                new TranscriptionUsage,
                new Meta,
            ),
        ]);

        $response = Transcription::of(base64_encode('audio-1'))->generate();
        $this->assertSame('First transcription', $response->text);

        $response = Transcription::of(base64_encode('audio-2'))->generate();
        $this->assertSame('Second transcription', $response->text);

        $response = Transcription::of(base64_encode('audio-3'))->generate();
        $this->assertSame('Third transcription', $response->text);

        // Assertion tests...
        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): true => true);
        Transcription::assertNotGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'fr');
    }

    public function testCanAssertNoTranscriptionsWereGenerated(): void
    {
        Transcription::fake();

        Transcription::assertNothingGenerated();
    }

    public function testTranscriptionsCanBeFakedWithNoPredefinedResponses(): void
    {
        Transcription::fake();

        $response = Transcription::of(base64_encode('audio-1'))->generate();
        $this->assertSame('Fake transcription text.', $response->text);

        $response = Transcription::of(base64_encode('audio-2'))->generate();
        $this->assertSame('Fake transcription text.', $response->text);
    }

    public function testTranscriptionsCanBeFakedWithASingleClosureThatIsInvokedForEveryGeneration(): void
    {
        $counter = 0;

        Transcription::fake(function (TranscriptionPrompt $prompt) use (&$counter): string {
            ++$counter;

            return "Transcription {$counter}";
        });

        $response = Transcription::of(base64_encode('audio-1'))->generate();
        $this->assertSame('Transcription 1', $response->text);

        $response = Transcription::of(base64_encode('audio-2'))->generate();
        $this->assertSame('Transcription 2', $response->text);
    }

    public function testTranscriptionsCanPreventStrayGenerations(): void
    {
        Transcription::fake()->preventStrayTranscriptions();

        $this->expectException(RuntimeException::class);
        Transcription::of(base64_encode('audio'))->generate();
    }

    public function testFakeClosuresCanThrowExceptions(): void
    {
        Transcription::fake(function (): void {
            throw new Exception('Something went wrong');
        });

        $this->expectException(Exception::class);
        Transcription::of(base64_encode('audio'))->generate();
    }

    public function testTranscriptionLanguageAndDiarizeAreRecorded(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))->language('en')->diarize()->generate();

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'en' && $prompt->isDiarized());
    }

    public function testTranscriptionProviderOptionsAreRecorded(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))
            ->withProviderOptions(['prompt' => 'Hypervel Forge and Vapor'])
            ->generate();

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => ($prompt->providerOptions['prompt'] ?? null) === 'Hypervel Forge and Vapor');
    }

    public function testFakeTranscriptionsIncludeSegments(): void
    {
        Transcription::fake(['Hello world']);

        $response = Transcription::of(base64_encode('audio'))->generate();

        $this->assertCount(1, $response->segments);
        $this->assertSame('Hello world', $response->segments[0]->text);
        $this->assertSame('Speaker 1', $response->segments[0]->speaker);
    }

    public function testQueuedTranscriptionsCanBeFaked(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->queue();

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');
        Transcription::assertNotQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/other.mp3');

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');

        Transcription::assertNotQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/other.mp3');
    }

    public function testQueuedTranscriptionsCanBeFakedAndThenCallbackIsExecuted(): void
    {
        Transcription::fake(['Some transcription text']);

        $GLOBALS['transcriptionResponse'] = null;

        Transcription::fromPath('/path/to/audio.mp3')->queue()->then(static function (TranscriptionResponse $response): void {
            $GLOBALS['transcriptionResponse'] = $response;
        });

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');

        $this->assertInstanceOf(TranscriptionResponse::class, $GLOBALS['transcriptionResponse']);
        $this->assertSame('Some transcription text', $GLOBALS['transcriptionResponse']->text);
    }

    public function testQueuedTranscriptionsCanBeFakedAndThenCallbackIsNotExecutedIfQueueIsFaked(): void
    {
        Transcription::fake(['Some transcription text']);
        Queue::fake();

        $GLOBALS['transcriptionResponse'] = null;

        Transcription::fromPath('/path/to/audio.mp3')->queue()->then(static function (TranscriptionResponse $response): void {
            $GLOBALS['transcriptionResponse'] = $response;
        });

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->audio->path === '/path/to/audio.mp3');

        $this->assertNull($GLOBALS['transcriptionResponse']);

        Queue::assertPushed(GenerateTranscription::class);
    }

    public function testCanAssertNoTranscriptionsWereQueued(): void
    {
        Transcription::fake();

        Transcription::assertNothingQueued();
    }

    public function testGenerateAcceptsAiProviderEnum(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))->generate(provider: Lab::OpenAI);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): true => true);
    }

    public function testQueuedTranscriptionAcceptsAiProviderEnum(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->queue(provider: Lab::ElevenLabs);

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->provider === Lab::ElevenLabs);
    }

    public function testQueuedTranscriptionLanguageAndDiarizeAreRecorded(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->language('es')->diarize()->queue();

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->language === 'es' && $prompt->isDiarized());
    }

    public function testQueuedTranscriptionProviderOptionsAreRecorded(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')
            ->withProviderOptions(['prompt' => 'Hypervel Forge and Vapor'])
            ->queue();

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => ($prompt->providerOptions['prompt'] ?? null) === 'Hypervel Forge and Vapor');
    }

    public function testTranscriptionCanHaveTimeouts(): void
    {
        Transcription::fake();

        Transcription::of(base64_encode('audio'))->timeout(60)->generate();

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->timeout === 60);
    }

    public function testQueuedTranscriptionTimeoutIsRecorded(): void
    {
        Transcription::fake();

        Transcription::fromPath('/path/to/audio.mp3')->timeout(90)->queue();

        Transcription::assertQueued(fn (QueuedTranscriptionPrompt $prompt): bool => $prompt->timeout === 90);
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Transcription failed.');
        Transcription::fake([fn (): never => throw $exception, 'Second transcription']);

        try {
            Transcription::of(base64_encode('audio'))->generate(provider: Lab::ElevenLabs);
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('Second transcription', Transcription::of(base64_encode('audio'))->generate(provider: Lab::ElevenLabs)->text);
    }
}
