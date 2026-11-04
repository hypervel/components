<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Http\Client\Response;
use Hypervel\Tests\Ai\Fixtures\Responses\SubclassedStep;
use Hypervel\Tests\Ai\Fixtures\Responses\SubclassedTextResponse;
use Hypervel\Tests\TestCase;

class ResponseSerializationTest extends TestCase
{
    public function testSerializationPreservesPrivatePropertiesDeclaredOnTextResponseSubclasses(): void
    {
        $response = new SubclassedTextResponse('Hello', new TextUsage(1, 2), new Meta('anthropic', 'claude'));
        $response->rememberSecret('changed');
        $response->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertSame('changed', $restored->secret());
        $this->assertSame('Hello', $restored->text);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesPrivatePropertiesDeclaredOnStepSubclasses(): void
    {
        $step = new SubclassedStep('Hello', [], [], FinishReason::Stop, new TextUsage(1, 2), new Meta('anthropic', 'claude'), '', []);
        $step->rememberSecret('changed');
        $step->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($step));

        $this->assertSame('changed', $restored->secret());
        $this->assertSame('Hello', $restored->text);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesClassificationAnswersAndDropsTheRawResponse(): void
    {
        $response = (new ClassificationResponse(['is_urgent' => new BooleanAnswer(0.9)], new TextUsage(1, 2), new Meta('openai', 'gpt-6-luna')))
            ->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertSame(0.9, $restored->answer('is_urgent')->probability);
        $this->assertSame(2, $restored->usage->outputTokens);
        $this->assertSame('gpt-6-luna', $restored->meta->model);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesEmbeddingsAndDropsTheRawResponse(): void
    {
        $response = (new EmbeddingsResponse([[0.1, 0.2], [0.3, 0.4]], new Usage(10), new Meta('openai', 'text-embedding-3-small')))
            ->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $restored->embeddings);
        $this->assertSame(10, $restored->usage->inputTokens);
        $this->assertSame('openai', $restored->meta->provider);
        $this->assertSame('text-embedding-3-small', $restored->meta->model);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesTranscriptionDataAndDropsTheRawResponse(): void
    {
        $response = (new TranscriptionResponse(
            'Hello, world!',
            collect([new TranscriptionSegment('Hello, world!', 'Speaker 1', 1.5, 3.25)]),
            new TranscriptionUsage(10, 4, audioSeconds: 3.25),
            new Meta('openai', 'gpt-4o-transcribe'),
        ))->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertSame('Hello, world!', $restored->text);
        $this->assertSame([
            ['text' => 'Hello, world!', 'speaker' => 'Speaker 1', 'start_seconds' => 1.5, 'end_seconds' => 3.25],
        ], $restored->segments->toArray());
        $this->assertSame(10, $restored->usage->inputTokens);
        $this->assertSame(4, $restored->usage->outputTokens);
        $this->assertSame(3.25, $restored->usage->audioSeconds);
        $this->assertSame('openai', $restored->meta->provider);
        $this->assertSame('gpt-4o-transcribe', $restored->meta->model);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesImageDataAndDropsTheRawResponse(): void
    {
        $response = (new ImageResponse(
            collect([new GeneratedImage(base64_encode('image-content'), 'image/webp')]),
            new ImageUsage(41, 1024, imageOutputTokens: 1000),
            new Meta('openai', 'gpt-image-1'),
        ))->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertCount(1, $restored->images);
        $this->assertSame('image-content', $restored->firstImage()->content());
        $this->assertSame('image/webp', $restored->firstImage()->mime());
        $this->assertSame(41, $restored->usage->inputTokens);
        $this->assertSame(1024, $restored->usage->outputTokens);
        $this->assertSame(1000, $restored->usage->imageOutputTokens);
        $this->assertSame('openai', $restored->meta->provider);
        $this->assertSame('gpt-image-1', $restored->meta->model);
        $this->assertNull($restored->raw);
    }

    public function testSerializationPreservesRerankingDataAndDropsTheRawResponse(): void
    {
        $response = (new RerankingResponse(
            [new RankedDocument(1, 'Hypervel is a PHP framework', 0.95), new RankedDocument(0, 'React is a JS library', 0.12)],
            new RerankingUsage(320, 2.0),
            new Meta('cohere', 'rerank-v3.5'),
        ))->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

        $restored = unserialize(serialize($response));

        $this->assertInstanceOf(RerankingResponse::class, $restored);
        $this->assertEquals($response->results, $restored->results);
        $this->assertEquals(new RerankingUsage(320, 2.0), $restored->usage);
        $this->assertEquals(new Meta('cohere', 'rerank-v3.5'), $restored->meta);
        $this->assertNull($restored->raw);
    }
}
