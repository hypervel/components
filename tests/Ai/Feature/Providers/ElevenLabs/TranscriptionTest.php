<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\ElevenLabs;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class TranscriptionTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.eleven.key', 'test-key');
    }

    public function testTranscriptionRequestPostsToSpeechToTextWithModelLanguageCodeAndDiarizeFlag(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse()]);

        Transcription::of(base64_encode('fake-audio'))
            ->language('en')
            ->generate(provider: 'eleven', model: 'scribe_v2');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
            && $request->isMultipart()
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'model_id' && $part['contents'] === 'scribe_v2')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'language_code' && $part['contents'] === 'en')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'diarize' && $part['contents'] === 'false'));
    }

    public function testTranscriptionRequestSendsDiarizeTrueWhenEnabled(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse(diarized: true)]);

        Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'eleven', model: 'scribe_v2');

        Http::assertSent(
            fn (Request $request): bool => collect($request->data())
                ->contains(fn (array $part): bool => $part['name'] === 'diarize' && $part['contents'] === 'true')
        );
    }

    public function testTranscriptionSendsEnableLoggingAsAQueryParameterInsteadOfInTheBody(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse()]);

        Transcription::of(base64_encode('fake-audio'))
            ->withProviderOptions(['enable_logging' => false, 'tag_audio_events' => 'true'])
            ->generate(provider: 'eleven', model: 'scribe_v2');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text?enable_logging=false'
            && ! collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'enable_logging')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'tag_audio_events' && $part['contents'] === 'true'));
    }

    public function testTranscriptionRequestSendsXiApiKeyHeader(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse()]);

        Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('xi-api-key', 'test-key'));
    }

    public function testTranscriptionResponseReturnsPlainTextWithNoSegmentsWhenDiarizeIsOff(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse()]);

        $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

        $this->assertSame('Hello world', $response->text);
        $this->assertCount(0, $response->segments);
        $this->assertSame('eleven', $response->meta->provider);
        $this->assertSame('scribe_v2', $response->meta->model);
    }

    public function testTranscriptionResponseBuildsSegmentsFromWordsWhenDiarizeIsOn(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse(diarized: true)]);

        $response = Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'eleven', model: 'scribe_v2');

        $this->assertCount(2, $response->segments);
        $this->assertSame('Hello', $response->segments[0]->text);
        $this->assertSame('speaker_0', $response->segments[0]->speaker);
        $this->assertSame(0.0, $response->segments[0]->startSeconds);
        $this->assertSame(0.5, $response->segments[0]->endSeconds);
        $this->assertSame('world', $response->segments[1]->text);
        $this->assertSame('speaker_1', $response->segments[1]->speaker);
    }

    public function testTranscriptionFiltersOutNonWordSegments(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello world',
            'words' => [
                ['type' => 'word', 'text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
                ['type' => 'spacing', 'text' => ' ', 'start' => 0.5, 'end' => 0.6],
                ['type' => 'word', 'text' => 'world', 'speaker_id' => 'speaker_0', 'start' => 0.6, 'end' => 1.0],
            ],
        ])]);

        $response = Transcription::of(base64_encode('fake-audio'))
            ->diarize()
            ->generate(provider: 'eleven', model: 'scribe_v2');

        $this->assertCount(2, $response->segments);
        $this->assertSame(['Hello', 'world'], $response->segments->pluck('text')->all());
    }

    public function testTranscriptionUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeElevenTranscriptionResponse()]);

        Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven');

        Http::assertSent(
            fn (Request $request): bool => collect($request->data())
                ->contains(fn (array $part): bool => $part['name'] === 'model_id' && $part['contents'] === 'scribe_v2')
        );
    }

    public function testTranscriptionThrowsWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

        Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');
    }

    /**
     * Create a transcription response with optional speaker segments.
     */
    protected function fakeElevenTranscriptionResponse(bool $diarized = false): PromiseInterface
    {
        $body = ['text' => 'Hello world'];

        if ($diarized) {
            $body['words'] = [
                ['type' => 'word', 'text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
                ['type' => 'word', 'text' => 'world', 'speaker_id' => 'speaker_1', 'start' => 0.6, 'end' => 1.0],
            ];
        }

        return Http::response($body);
    }

    public function testTranscriptionReportsTheTranscribedAudioDuration(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!', 'audio_duration_secs' => 41.2])]);

        $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven');

        $this->assertSame(41.2, $response->usage->audioSeconds);
    }

    public function testTranscriptionLeavesTheAudioDurationNullWhenNotReturned(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven');

        $this->assertNull($response->usage->audioSeconds);
    }
}
