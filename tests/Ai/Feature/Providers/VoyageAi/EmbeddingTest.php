<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\VoyageAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class EmbeddingTest extends TestCase
{
    /**
     * Configure the VoyageAI credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.voyageai.key', 'test-key');
    }

    public function testEmbeddingsRequestIncludesModelInputAndOutputDimension(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'voyage-4'
                && $body['input'] === ['Hello world']
                && $body['output_dimension'] === 1024
                && $request->url() === 'https://api.voyageai.com/v1/embeddings';
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'voyageai', model: 'voyage-4');

        $this->assertCount(1, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('voyageai', $response->meta->provider);
        $this->assertSame('voyage-4', $response->meta->model);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'model' => 'voyage-4',
            'usage' => ['total_tokens' => 20],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'voyageai', model: 'voyage-4');

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
    }

    public function testEmbeddingsDefaultTo1024DimensionsWhenNoneSpecified(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['output_dimension'] === 1024);
    }

    public function testImageEmbeddingsUseTheMultimodalEndpoint(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        $response = Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
                && data_get($body, 'inputs.0.content.0.type') === 'image_base64'
                && data_get($body, 'inputs.0.content.0.image_base64') === 'data:image/png;base64,' . base64_encode('image-bytes')
                && ! array_key_exists('output_dimension', $body);
        });

        $this->assertCount(1, $response->embeddings);
    }

    public function testTextOnlyInputsUseTheMultimodalEndpointForMultimodalModels(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for(['red sneakers'])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
                && data_get($body, 'inputs.0.content.0.type') === 'text'
                && data_get($body, 'inputs.0.content.0.text') === 'red sneakers';
        });
    }

    public function testUnsupportedDimensionsAreRejectedForVoyageMultimodal3(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only supports 1024 dimension embeddings');
        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->dimensions(512)->generate(provider: 'voyageai', model: 'voyage-multimodal-3');
    }

    public function testMultimodalEmbeddingsSendOutputDimensionForModelsThatSupportIt(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->dimensions(512)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['output_dimension'] === 512);
    }

    public function testMultimodalEmbeddingsForwardProviderOptions(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->withProviderOptions(['input_type' => 'query', 'model' => 'hijacked'])
            ->dimensions(1024)
            ->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'input_type') === 'query'
                && data_get($body, 'model') === 'voyage-multimodal-3.5';
        });
    }

    public function testImageEmbeddingsRequireAMultimodalModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('voyage-multimodal-3.5');
        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'voyageai');
    }

    public function testVideoEmbeddingsRequireTheLatestMultimodalModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('voyage-multimodal-3.5');
        Embeddings::for([
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->generate(provider: 'voyageai', model: 'voyage-multimodal-3');
    }

    public function testBase64VideoEmbeddingsUseTheMultimodalEndpoint(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        $response = Embeddings::for([
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
                && data_get($body, 'model') === 'voyage-multimodal-3.5'
                && data_get($body, 'inputs.0.content.0.type') === 'video_base64'
                && data_get($body, 'inputs.0.content.0.video_base64') === 'data:video/mp4;base64,' . base64_encode('video-bytes');
        });

        $this->assertCount(1, $response->embeddings);
    }

    public function testRemoteVideoEmbeddingsPreserveVideoUrls(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for([
            Video::fromUrl('https://example.com/demo.mp4'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'inputs.0.content.0.type') === 'video_url'
                && data_get($body, 'inputs.0.content.0.video_url') === 'https://example.com/demo.mp4';
        });
    }

    public function testMultimodalEmbeddingsRejectMixedUrlAndBase64MediaSources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('either URL media or base64 media exclusively');
        Http::preventStrayRequests();

        Embeddings::for([
            Image::fromUrl('https://example.com/avatar.png'),
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');
    }

    public function testMultimodalEmbeddingsAllowMixedMediaTypesWithTheSameSourceType(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for([
            Image::fromUrl('https://example.com/avatar.png'),
            Video::fromUrl('https://example.com/demo.mp4'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'inputs.0.content.0.type') === 'image_url'
                && data_get($body, 'inputs.1.content.0.type') === 'video_url';
        });
    }

    public function testMultimodalEmbeddingsAllowTextMixedWithMediaInputs(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        $response = Embeddings::for([
            'Hello world',
            Image::fromUrl('https://example.com/avatar.png'),
            Video::fromUrl('https://example.com/demo.mp4'),
        ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
                && data_get($body, 'inputs.0.content.0.type') === 'text'
                && data_get($body, 'inputs.0.content.0.text') === 'Hello world'
                && data_get($body, 'inputs.1.content.0.type') === 'image_url'
                && data_get($body, 'inputs.2.content.0.type') === 'video_url';
        });

        $this->assertCount(1, $response->embeddings);
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.voyageai.com/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.voyageai.com/*' => Http::response(['detail' => 'Service unavailable'], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.voyageai.com/*' => Http::response(['detail' => 'Invalid model'], 400),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['input_type' => 'query', 'truncation' => true])
            ->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['input_type'] === 'query'
                && $body['truncation'] === true;
        });
    }
}

/**
 * Create a VoyageAI embedding response.
 */
function fakeVoyageEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 10],
    ]);
}
