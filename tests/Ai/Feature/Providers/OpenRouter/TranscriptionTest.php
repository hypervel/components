<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Create an OpenRouter transcription response.
 */
function fakeOpenRouterTranscriptionResponse(string $text = 'Hello, world!'): PromiseInterface
{
    return Http::response([
        'text' => $text,
        'usage' => [
            'seconds' => 1.5,
            'total_tokens' => 30,
            'input_tokens' => 20,
            'output_tokens' => 10,
            'cost' => 0.000100,
        ],
    ]);
}

class TranscriptionTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testTranscriptionRequestPostsToCorrectEndpointAsJson(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/audio/transcriptions'
            && str_contains($request->header('Content-Type')[0] ?? '', 'application/json'));
    }

    public function testTranscriptionRequestSendsAudioAsBase64WithFormatFromMimeType(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['input_audio']['data'] === base64_encode('fake-audio')
                && $body['input_audio']['format'] === 'mp3';
        });
    }

    public function testTranscriptionDiarizeThrowsLogicExceptionWithoutSendingRequest(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OpenRouter does not support diarized transcription');

        try {
            Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
                ->diarize()
                ->generate(provider: 'openrouter');
        } finally {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('audioMimeTypes')]
    public function testTranscriptionMapsAudioMimeTypesToOpenRouterFormatValues(string $mimeType, string $expectedFormat): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), $mimeType)->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input_audio']['format'] === $expectedFormat);
    }

    /**
     * Get audio MIME types and their OpenRouter formats.
     */
    public static function audioMimeTypes(): array
    {
        return [
            'mp3 via audio/mpeg' => ['audio/mpeg', 'mp3'],
            'mp3 via audio/mp3' => ['audio/mp3', 'mp3'],
            'wav via audio/wav' => ['audio/wav', 'wav'],
            'wav via audio/x-wav' => ['audio/x-wav', 'wav'],
            'm4a via audio/m4a' => ['audio/m4a', 'm4a'],
            'm4a via audio/mp4' => ['audio/mp4', 'm4a'],
            'm4a via audio/x-m4a' => ['audio/x-m4a', 'm4a'],
            'ogg via audio/ogg' => ['audio/ogg', 'ogg'],
            'ogg via audio/ogg opus' => ['audio/ogg; codecs=opus', 'ogg'],
            'flac via audio/flac' => ['audio/flac', 'flac'],
            'aiff via audio/aiff' => ['audio/aiff', 'aiff'],
            'flac via audio/x-flac' => ['audio/x-flac', 'flac'],
            'webm via audio/webm' => ['audio/webm', 'webm'],
            'aac via audio/aac' => ['audio/aac', 'aac'],
        ];
    }

    public function testTranscriptionWrapsRawPcmAudioInAWavHeaderAndSendsAsWavFormat(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        $pcm = str_repeat("\x01\x00", 1000);

        Transcription::fromBase64(base64_encode($pcm), 'audio/pcm')->generate(provider: 'openrouter');

        Http::assertSent(function (Request $request) use ($pcm): bool {
            $body = json_decode($request->body(), true);

            $sent = base64_decode((string) $body['input_audio']['data']);

            return $body['input_audio']['format'] === 'wav'
                && str_starts_with($sent, 'RIFF')
                && substr($sent, 8, 4) === 'WAVE'
                && str_ends_with($sent, $pcm);
        });
    }

    public function testTranscriptionThrowsInvalidArgumentExceptionForUnsupportedMimeType(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported audio MIME type [audio/midi]');

        try {
            Transcription::fromBase64(base64_encode('fake-audio'), 'audio/midi')->generate(provider: 'openrouter');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testTranscriptionRequestIncludesLanguageWhenSpecified(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->language('fr')
            ->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['language'] === 'fr');
    }

    public function testTranscriptionRequestOmitsLanguageWhenNotSpecified(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('language', json_decode($request->body(), true)));
    }

    public function testTranscriptionUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'openai/gpt-transcribe');
    }

    public function testTranscriptionResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse('Hello, world!')]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        $this->assertSame('Hello, world!', $response->text);
        $this->assertCount(0, $response->segments);
        $this->assertSame('openrouter', $response->meta->provider);
        $this->assertSame('openai/gpt-transcribe', $response->meta->model);
    }

    public function testTranscriptionUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello',
            'usage' => [
                'seconds' => 2.0,
                'total_tokens' => 150,
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cost' => 0.0005,
            ],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
    }

    public function testTranscriptionRequestSendsBearerToken(): void
    {
        Http::fake(['*' => fakeOpenRouterTranscriptionResponse()]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testTranscriptionRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit exceeded',
                    'code' => 429,
                ],
            ], 429),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openrouter', model: 'openai/whisper-1');
    }

    public function testTranscriptionOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'message' => 'Service overloaded',
                    'code' => 503,
                ],
            ], 503),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openrouter', model: 'openai/whisper-1');
    }

    public function testTranscriptionHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'message' => 'Invalid audio format',
                    'code' => 400,
                ],
            ], 400),
        ]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openrouter', model: 'openai/whisper-1');
    }

    public function testTranscriptionReportsTheAudioDurationReturnedInUsage(): void
    {
        Http::fake(['*' => Http::response([
            'text' => 'Hello, world!',
            'usage' => ['seconds' => 9.2, 'input_tokens' => 83, 'output_tokens' => 30],
        ])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openrouter', model: 'openai/whisper-1');

        $this->assertSame(9.2, $response->usage->audioSeconds);
        $this->assertSame(83, $response->usage->inputTokens);
    }

    public function testTranscriptionLeavesTheAudioDurationNullWhenNotReturned(): void
    {
        Http::fake(['*' => Http::response(['text' => 'Hello, world!'])]);

        $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->generate(provider: 'openrouter', model: 'openai/whisper-1');

        $this->assertNull($response->usage->audioSeconds);
    }
}
