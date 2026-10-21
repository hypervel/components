<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Exception;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\GenerateAudio;
use Hypervel\Ai\Prompts\AudioPrompt;
use Hypervel\Ai\Prompts\QueuedAudioPrompt;
use Hypervel\Ai\Providers\ElevenLabsProvider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class AudioFakeTest extends TestCase
{
    /**
     * Clear serialized callback results.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['audioResponse']);

        parent::tearDown();
    }

    public function testAudioRejectsEmptyText(): void
    {
        Audio::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text content is required to generate audio.');
        Audio::of('')->generate();
    }

    public function testAudioRejectsWhitespaceOnlyText(): void
    {
        Audio::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text content is required to generate audio.');
        Audio::of(" \t\n")->generate();
    }

    public function testAudioCanBeFaked(): void
    {
        Audio::fake([
            base64_encode('first-audio'),
            fn (AudioPrompt $prompt): string => base64_encode('second-audio-' . $prompt->text),
            new AudioResponse(base64_encode('third-audio'), new Usage, new Meta),
        ]);

        $response = Audio::of('First text')->generate();
        $this->assertSame(base64_encode('first-audio'), $response->audio);

        $response = Audio::of('Second text')->generate();
        $this->assertSame(base64_encode('second-audio-Second text'), $response->audio);

        $response = Audio::of('Third text')->generate();
        $this->assertSame(base64_encode('third-audio'), $response->audio);

        // Assertion tests...
        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'First text');
        Audio::assertNotGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Missing text');

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'First text');
    }

    public function testCanAssertNoAudioWasGenerated(): void
    {
        Audio::fake();

        Audio::assertNothingGenerated();
    }

    public function testAudioCanBeFakedWithNoPredefinedResponses(): void
    {
        Audio::fake();

        $response = Audio::of('First text')->generate();
        $this->assertSame(base64_encode('fake-audio-content'), $response->audio);

        $response = Audio::of('Second text')->generate();
        $this->assertSame(base64_encode('fake-audio-content'), $response->audio);
    }

    public function testAudioCanBeFakedWithASingleClosureThatIsInvokedForEveryGeneration(): void
    {
        Audio::fake(fn (AudioPrompt $prompt): string => base64_encode('audio-for-' . $prompt->text));

        $response = Audio::of('First text')->generate();
        $this->assertSame(base64_encode('audio-for-First text'), $response->audio);

        $response = Audio::of('Second text')->generate();
        $this->assertSame(base64_encode('audio-for-Second text'), $response->audio);
    }

    public function testAudioTimeoutDefaultsToSdkFallback(): void
    {
        Audio::fake();

        Audio::of('Hello world')->generate();

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->timeout === 30);
    }

    public function testFakeAudioClosureReceivesTimeout(): void
    {
        Audio::fake(function (AudioPrompt $prompt): string {
            $this->assertSame(45, $prompt->timeout);

            return base64_encode('audio-for-' . $prompt->text);
        });

        Audio::of('Hello world')->timeout(45)->generate();
    }

    public function testAudioCanBeGeneratedFromStringableMacro(): void
    {
        Audio::fake();

        $response = Str::of('Hello world')->toAudio();

        $this->assertSame(base64_encode('fake-audio-content'), $response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello world');
    }

    public function testStringableAudioMacroPassesThroughOptions(): void
    {
        Audio::fake();

        Str::of('Hello world')->toAudio(
            provider: Lab::ElevenLabs,
            voice: 'alloy',
            instructions: 'Speak slowly',
            model: 'custom-model',
            timeout: 45,
        );

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello world'
            && $prompt->provider instanceof ElevenLabsProvider
            && $prompt->voice === 'alloy'
            && $prompt->instructions === 'Speak slowly'
            && $prompt->model === 'custom-model'
            && $prompt->timeout === 45);
    }

    public function testAudioCanPreventStrayGenerations(): void
    {
        Audio::fake()->preventStrayAudio();

        $this->expectException(RuntimeException::class);
        Audio::of('First text')->generate();
    }

    public function testFakeClosuresCanThrowExceptions(): void
    {
        Audio::fake(function (): void {
            throw new Exception('Something went wrong');
        });

        $this->expectException(Exception::class);
        Audio::of('Test text')->generate();
    }

    public function testAudioVoiceAndInstructionsAreRecorded(): void
    {
        Audio::fake();

        Audio::of('Hello world')->voice('alloy')->instructions('Speak slowly')->generate();

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Hello world'
            && $prompt->voice === 'alloy'
            && $prompt->instructions === 'Speak slowly');
    }

    public function testAudioIsStoredUnderARandomNameDerivedFromItsMimeType(): void
    {
        Storage::fake('audio');

        Audio::fake([
            new AudioResponse(base64_encode('wav-bytes'), new Usage, new Meta, 'audio/wav'),
            new AudioResponse(base64_encode('alias-bytes'), new Usage, new Meta, 'audio/x-wav'),
            new AudioResponse(base64_encode('mp3-bytes'), new Usage, new Meta),
            new AudioResponse(base64_encode('pcm-bytes'), new Usage, new Meta, 'audio/pcm'),
        ]);

        $wav = Audio::of('First text')->generate()->store('generated', 'audio');
        $alias = Audio::of('Second text')->generate()->store('generated', 'audio');
        $default = Audio::of('Third text')->generate()->store('generated', 'audio');
        $pcm = Audio::of('Fourth text')->generate()->store('generated', 'audio');

        $this->assertStringStartsWith('generated/', $wav);
        $this->assertStringEndsWith('.wav', $wav);
        $this->assertStringEndsWith('.wav', $alias);
        $this->assertStringEndsWith('.mp3', $default);
        $this->assertStringEndsWith('.pcm', $pcm);
        $this->assertSame('wav-bytes', Storage::disk('audio')->get($wav));
        $this->assertSame('mp3-bytes', Storage::disk('audio')->get($default));
    }

    public function testAudioCanBeStoredUnderAnExplicitPathAndName(): void
    {
        Storage::fake('audio');

        Audio::fake([base64_encode('raw-bytes')]);

        $response = Audio::of('Hello world')->generate();

        $this->assertSame('generated/hello.mp3', $response->storeAs('generated', 'hello.mp3', 'audio'));
        $this->assertSame('hello.mp3', $response->storeAs('hello.mp3', null, 'audio'));
        $this->assertSame('raw-bytes', Storage::disk('audio')->get('generated/hello.mp3'));
        $this->assertSame('raw-bytes', Storage::disk('audio')->get('hello.mp3'));
    }

    public function testStoringAudioPubliclyPassesPublicVisibilityToTheDisk(): void
    {
        Storage::fake('audio', ['visibility' => 'private']);

        Audio::fake([base64_encode('raw-bytes')]);

        $response = Audio::of('Hello world')->generate();

        $private = $response->store('private', 'audio');
        $public = $response->storePublicly('public', 'audio');
        $named = $response->storePubliclyAs('hello.mp3', null, 'audio');

        $this->assertSame('private', Storage::disk('audio')->getVisibility($private));
        $this->assertSame('public', Storage::disk('audio')->getVisibility($public));
        $this->assertSame('public', Storage::disk('audio')->getVisibility($named));
        $this->assertSame('hello.mp3', $named);
    }

    public function testQueuedAudioCanBeFaked(): void
    {
        Audio::fake();

        Audio::of('First text')->queue();

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');
        Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->contains('Second text'));

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

        Audio::assertNotQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Second text');
    }

    public function testQueuedAudioCanBeFakedAndThenCallbackIsExecuted(): void
    {
        Audio::fake([base64_encode('audio')]);

        $GLOBALS['audioResponse'] = null;

        Audio::of('First text')->queue()->then(static function (AudioResponse $response): void {
            $GLOBALS['audioResponse'] = $response;
        });

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

        $this->assertInstanceOf(AudioResponse::class, $GLOBALS['audioResponse']);
        $this->assertSame(base64_encode('audio'), $GLOBALS['audioResponse']->audio);
    }

    public function testQueuedAudioCanBeFakedAndThenCallbackIsNotExecutedIfQueueIsFaked(): void
    {
        Audio::fake([base64_encode('audio')]);
        Queue::fake();

        $GLOBALS['audioResponse'] = null;

        Audio::of('First text')->queue()->then(static function (AudioResponse $response): void {
            $GLOBALS['audioResponse'] = $response;
        });

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'First text');

        $this->assertNull($GLOBALS['audioResponse']);

        Queue::assertPushed(GenerateAudio::class);
    }

    public function testCanAssertNoAudioWasQueued(): void
    {
        Audio::fake();

        Audio::assertNothingQueued();
    }

    public function testGenerateAcceptsAiProviderEnum(): void
    {
        Audio::fake();

        Audio::of('Enum audio')->generate(provider: Lab::OpenAI);

        Audio::assertGenerated(fn (AudioPrompt $prompt): bool => $prompt->text === 'Enum audio');
    }

    public function testQueuedAudioAcceptsAiProviderEnum(): void
    {
        Audio::fake();

        Audio::of('Queued enum audio')->queue(provider: Lab::ElevenLabs);

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Queued enum audio'
            && $prompt->provider === Lab::ElevenLabs);
    }

    public function testQueuedAudioVoiceAndInstructionsAreRecorded(): void
    {
        Audio::fake();

        Audio::of('Hello world')->male()->instructions('Speak quickly')->queue();

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->text === 'Hello world'
            && $prompt->voice === 'default-male'
            && $prompt->instructions === 'Speak quickly');
    }

    public function testQueuedAudioTimeoutIsRecorded(): void
    {
        Audio::fake();

        Audio::of('Hello world')->timeout(90)->queue();

        Audio::assertQueued(fn (QueuedAudioPrompt $prompt): bool => $prompt->timeout === 90 && $prompt->contains('Hello world'));
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Audio generation failed.');
        Audio::fake([fn (): never => throw $exception, base64_encode('second-audio')]);

        try {
            Audio::of('First text')->generate(provider: Lab::ElevenLabs);
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(base64_encode('second-audio'), Audio::of('Second text')->generate(provider: Lab::ElevenLabs)->audio);
    }
}
