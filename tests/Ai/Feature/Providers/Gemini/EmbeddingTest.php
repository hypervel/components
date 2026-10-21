<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

/**
 * Create an embedding response.
 */
function fakeGeminiEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'embeddings' => [
            ['values' => [0.1, 0.2, 0.3]],
        ],
        'usageMetadata' => [
            'promptTokenCount' => 10,
        ],
    ]);
}

/**
 * Create a batch embedding response.
 */
function fakeGeminiBatchEmbeddingsResponse(array $embeddings = [[0.1, 0.2, 0.3]], int $tokens = 10): PromiseInterface
{
    return Http::response([
        'embeddings' => array_map(fn (array $values): array => ['values' => $values], $embeddings),
        'usageMetadata' => [
            'promptTokenCount' => $tokens,
        ],
    ]);
}

class EmbeddingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.gemini.key', 'test-key');
    }

    public function testEmbeddingsRequestPostsToBatchEmbedContentsEndpoint(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-embedding-001:batchEmbedContents'));
    }

    public function testEmbeddingsRequestWrapsEachInputInARequestObject(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $firstRequest = data_get($body, 'requests.0');

            return $firstRequest['model'] === 'models/gemini-embedding-001'
                && $firstRequest['content']['parts'][0]['text'] === 'Hello world'
                && data_get($firstRequest, 'outputDimensionality') === 3072;
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        $this->assertCount(1, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('gemini', $response->meta->provider);
        $this->assertSame('gemini-embedding-001', $response->meta->model);
    }

    public function testMultipleInputsAreSentAsSeparateRequestsInTheBatch(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'embeddings' => [
                    ['values' => [0.1, 0.2, 0.3]],
                    ['values' => [0.4, 0.5, 0.6]],
                ],
                'usageMetadata' => ['promptTokenCount' => 20],
            ]),
        ]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return count($body['requests']) === 2
                && $body['requests'][0]['content']['parts'][0]['text'] === 'Hello'
                && $body['requests'][1]['content']['parts'][0]['text'] === 'World';
        });

        $this->assertCount(2, $response->embeddings);
    }

    public function testGeminiEmbedding2InputsAreSentInASingleBatchRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], 30),
        ]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
            && data_get($request->data(), 'requests.0.content.parts.0.text') === 'Hello'
            && data_get($request->data(), 'requests.1.content.parts.0.text') === 'World');

        $this->assertSame([
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
        ], $response->embeddings);
        $this->assertSame(30, $response->usage->inputTokens);
    }

    public function testExplicitDimensionsAreSentAsOutputDimensionality(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        Embeddings::for(['Hello world'])->dimensions(768)->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'requests.0.outputDimensionality') === 768;
        });
    }

    public function testMultimodalEmbeddingsRequireAnEmbedding2Model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gemini-embedding-2');
        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-001');
    }

    public function testBase64ImageEmbeddingsAreSentAsInlineData(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
                && data_get($body, 'requests.0.content.parts.0.inlineData.mimeType') === 'image/png'
                && data_get($body, 'requests.0.content.parts.0.inlineData.data') === base64_encode('image-bytes');
        });
    }

    public function testTextMixedWithMediaInputsIsSentInASingleBatchRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            'Hello world',
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
            && data_get($request->data(), 'requests.0.content.parts.0.text') === 'Hello world'
            && data_get($request->data(), 'requests.1.content.parts.0.inlineData.data') === base64_encode('image-bytes'));
    }

    public function testBase64AudioEmbeddingsAreSentAsInlineData(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Audio::fromBase64(base64_encode('audio-bytes'), 'audio/mpeg'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.content.parts.0.inlineData.mimeType') === 'audio/mpeg'
            && data_get($request->data(), 'requests.0.content.parts.0.inlineData.data') === base64_encode('audio-bytes'));
    }

    public function testBase64DocumentEmbeddingsAreSentAsInlineData(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Document::fromBase64(base64_encode('document-bytes'), 'application/pdf'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.content.parts.0.inlineData.mimeType') === 'application/pdf'
            && data_get($request->data(), 'requests.0.content.parts.0.inlineData.data') === base64_encode('document-bytes'));
    }

    public function testLocalFileEmbeddingsAreSentAsInlineData(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        $directory = ParallelTesting::tempDir('GeminiEmbeddingTest');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $path = $directory . '/image.png';
        file_put_contents($path, 'image-bytes');

        try {
            Embeddings::for([
                Image::fromPath($path, 'image/png'),
            ])->generate(provider: 'gemini', model: 'gemini-embedding-2');
        } finally {
            $files->deleteDirectory($directory);
        }

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.content.parts.0.inlineData.data') === base64_encode('image-bytes'));
    }

    public function testProviderFileEmbeddingsAreSentAsFileData(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Image::fromId('files/file_123'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
            && data_get($request->data(), 'requests.0.content.parts.0.fileData.fileUri') === 'files/file_123');
    }

    public function testMultimodalEmbeddingsAcceptCanonicalModelNames(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'models/gemini-embedding-2');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
            && ! str_contains($request->url(), 'models/models/'));
    }

    public function testMultimodalEmbeddingsStillAcceptPreviewModel(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'models/gemini-embedding-2-preview:batchEmbedContents'));
    }

    public function testRemoteVideoEmbeddingsPreserveFileUris(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
        ]);

        Embeddings::for([
            Video::fromUrl('https://www.youtube.com/watch?v=demo'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && data_get($request->data(), 'requests.0.content.parts.0.fileData.fileUri') === 'https://www.youtube.com/watch?v=demo');
    }

    public function testMissingEmbeddingsKeyInResponseReturnsEmptyArray(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'usageMetadata' => ['promptTokenCount' => 5],
            ]),
        ]);

        $response = Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        $this->assertSame([], $response->embeddings);
    }

    public function testMissingUsageMetadataInResponseReturnsZeroTokens(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'embeddings' => [['values' => [0.1, 0.2, 0.3]]],
            ]),
        ]);

        $response = Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        $this->assertSame(0, $response->usage->inputTokens);
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
    }

    public function testOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'The model is overloaded. Please try again later.',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
    }

    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
    }

    public function testRequestSendsXGoogApiKeyHeader(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function testEmbeddingsRequestMergesProviderOptionsIntoEachPerInputRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        Embeddings::for(['Hello', 'World'])
            ->withProviderOptions(['taskType' => 'RETRIEVAL_QUERY', 'title' => 'doc'])
            ->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            if (array_key_exists('taskType', $body) || array_key_exists('title', $body)) {
                return false;
            }

            foreach ($body['requests'] as $req) {
                if (($req['taskType'] ?? null) !== 'RETRIEVAL_QUERY' || ($req['title'] ?? null) !== 'doc') {
                    return false;
                }

                if (! isset($req['model'], $req['content'], $req['outputDimensionality'])) {
                    return false;
                }
            }

            return count($body['requests']) === 2;
        });
    }

    public function testGeminiProviderOptionsCannotOverrideFrameworkControlledPerRequestKeys(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
        ]);

        Embeddings::for(['Hello'])
            ->withProviderOptions([
                'model' => 'hijacked',
                'content' => ['hijacked'],
                'outputDimensionality' => 1,
                'output_dimensionality' => 1,
            ])
            ->generate(provider: 'gemini', model: 'gemini-embedding-001');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $req = $body['requests'][0];

            return $req['model'] === 'models/gemini-embedding-001'
                && $req['content'] === ['parts' => [['text' => 'Hello']]]
                && $req['outputDimensionality'] === 3072
                && ! array_key_exists('output_dimensionality', $req);
        });
    }
}
