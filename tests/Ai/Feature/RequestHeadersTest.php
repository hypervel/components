<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Image;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\multipartField;

require_once __DIR__ . '/../Fixtures/helpers.php';

function fakeEmbeddingsHeadersResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ]);
}

class RequestHeadersTest extends TestCase
{
    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set([
            'ai.providers.cohere.key' => 'test-key',
            'ai.providers.openai.key' => 'test-key',
            'ai.caching.embeddings.store' => 'array',
        ]);
    }

    public function testExtraHeadersAreSentWithEmbeddingsRequestsAndNeverInTheBody(): void
    {
        Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

        Embeddings::for(['Hello'])
            ->withHeaders(['X-Tenant' => 'acme'])
            ->withProviderOptions(['user' => 'acme'])
            ->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('X-Tenant', 'acme')
                && ! array_key_exists('X-Tenant', $body)
                && $body['user'] === 'acme';
        });
    }

    public function testExtraHeadersAreSentWithTranscriptionRequests(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['text' => 'Hello'])]);

        Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'acme')
            && multipartField($request, 'X-Tenant') === null);
    }

    public function testExtraHeadersResolvedFromAClosureSurviveSerialization(): void
    {
        Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

        $pending = Embeddings::for(['Hello'])->withHeaders(
            fn (Provider $provider): array => ['X-Tenant' => $provider->driver() . '-acme'],
        );

        unserialize(serialize($pending))->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'openai-acme'));
    }

    public function testExtraHeadersDoNotParticipateInTheEmbeddingsCacheKey(): void
    {
        Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withHeaders(['X-Tenant' => 'acme'])
            ->generate(provider: 'openai', model: 'text-embedding-3-small');

        Embeddings::for(['Hello'])
            ->cache(3600)
            ->withHeaders(['X-Tenant' => 'globex'])
            ->generate(provider: 'openai', model: 'text-embedding-3-small');

        $this->assertCount(1, Http::recorded());
    }

    public function testExtraHeadersReplaceConfiguredHeadersRegardlessOfCasing(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'headers' => ['X-Tenant' => 'from-config'],
        ]]);

        Http::fake(['api.openai.com/*' => fakeEmbeddingsHeadersResponse()]);

        Embeddings::for(['Hello'])
            ->withHeaders(['x-tenant' => 'from-request'])
            ->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => $request->header('X-Tenant') === ['from-request']);
    }

    public function testExtraHeadersAreSentWithImageRequests(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

        Image::of('A red apple')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->generate(provider: 'openai', model: 'dall-e-3');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('X-Tenant', 'acme')
                && ! array_key_exists('X-Tenant', $body);
        });
    }

    public function testExtraHeadersAreSentWithAudioRequests(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('fake-audio-bytes')]);

        Audio::of('Hello world')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('X-Tenant', 'acme')
                && ! array_key_exists('X-Tenant', $body);
        });
    }

    public function testExtraHeadersAreSentWithRerankingRequests(): void
    {
        Http::fake(['api.cohere.com/*' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.95]]])]);

        Reranking::of(['Hypervel is a PHP framework'])
            ->withHeaders(['X-Tenant' => 'acme'])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('X-Tenant', 'acme')
                && ! array_key_exists('X-Tenant', $body);
        });
    }

    public function testExtraHeadersAreSentWithFileUploads(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-abc123'])]);

        Document::fromString('Hello, World!', 'text/plain')
            ->as('hello.txt')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->put(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Tenant', 'acme')
            && multipartField($request, 'X-Tenant') === null);
    }

    public function testFileUploadProviderOptionsAreResolvedOnce(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-abc123'])]);

        $resolutions = 0;

        Document::fromString('Hello, World!', 'text/plain')
            ->withHeaders(function () use (&$resolutions): array {
                ++$resolutions;

                return ['X-Tenant' => 'acme'];
            })
            ->put(provider: 'openai');

        $this->assertSame(1, $resolutions);
    }
}
