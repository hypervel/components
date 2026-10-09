<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Jobs\GenerateEmbeddings;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class EmbeddingsFakeTest extends TestCase
{
    /**
     * Clear serialized callback results.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['embeddingsResponse']);

        parent::tearDown();
    }

    public function testEmbeddingsRejectEmptyInputList(): void
    {
        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one input is required to generate embeddings.');
        Embeddings::for([])->generate();
    }

    public function testEmbeddingsRejectAssociativeInputArray(): void
    {
        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Inputs to embed must be a list, not an associative array.');
        Embeddings::for(['first' => 'Hello world'])->generate();
    }

    public function testEmbeddingsRejectBlankStringInputs(): void
    {
        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input at index 0 must be a non-blank string.');
        Embeddings::for([''])->generate();
    }

    public function testEmbeddingsRejectWhitespaceOnlyStringInputs(): void
    {
        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input at index 0 must be a non-blank string.');
        Embeddings::for([" \t\n"])->generate();
    }

    public function testEmbeddingsRejectNonStringInputs(): void
    {
        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input at index 0 must be a string or an image, audio, document, or video file.');
        Embeddings::for([123])->generate();
    }

    public function testEmbeddingsReportTheOffendingIndexForBlankInputs(): void
    {
        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input at index 2 must be a non-blank string.');
        Embeddings::for(['valid', 'also valid', ''])->generate();
    }

    public function testCanFakeEmbeddings(): void
    {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->generate();

        $this->assertCount(1, $response);
        $this->assertCount(1536, $response->first());
    }

    public function testCanFakeEmbeddingsWithCustomDimensions(): void
    {
        Embeddings::fake();

        $response = Embeddings::for(['Hello world'])->dimensions(512)->generate();

        $this->assertCount(1, $response);
        $this->assertCount(512, $response->first());
    }

    public function testCanFakeEmbeddingsWithMultipleInputs(): void
    {
        Embeddings::fake();

        $response = Embeddings::for(['Hello', 'World', 'Test'])->generate();

        $this->assertCount(3, $response);
    }

    public function testCanIterateOverResponse(): void
    {
        Embeddings::fake([
            [
                array_fill(0, 3, 0.1),
                array_fill(0, 3, 0.2),
            ],
        ]);

        $response = Embeddings::for(['Hello', 'World'])->dimensions(3)->generate();

        $embeddings = [];

        foreach ($response as $embedding) {
            $embeddings[] = $embedding;
        }

        $this->assertSame([
            array_fill(0, 3, 0.1),
            array_fill(0, 3, 0.2),
        ], $embeddings);
    }

    public function testCanFakeEmbeddingsWithImageInput(): void
    {
        Embeddings::fake();

        $response = Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate();

        $this->assertCount(1, $response);
    }

    public function testCanFakeEmbeddingsWithAudioInput(): void
    {
        Embeddings::fake();

        $response = Embeddings::for([
            Audio::fromBase64(base64_encode('audio-bytes'), 'audio/mpeg'),
        ])->generate();

        $this->assertCount(1, $response);
    }

    public function testCanFakeEmbeddingsWithDocumentInput(): void
    {
        Embeddings::fake();

        $response = Embeddings::for([
            Document::fromBase64(base64_encode('%PDF-1.4 fake'), 'application/pdf'),
        ])->generate();

        $this->assertCount(1, $response);
    }

    public function testCanFakeEmbeddingsWithVideoInput(): void
    {
        Embeddings::fake();

        $response = Embeddings::for([
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->generate();

        $this->assertCount(1, $response);
    }

    public function testNonTextEmbeddingsInputsAreRejectedByTextOnlyProviders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider [openai] only supports text embeddings inputs.');
        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate(provider: 'openai');
    }

    public function testCanFakeEmbeddingsWithCustomResponse(): void
    {
        $customEmbedding = array_fill(0, 100, 0.5);

        Embeddings::fake([
            [$customEmbedding],
        ]);

        $response = Embeddings::for(['Hello world'])->dimensions(100)->generate();

        $this->assertSame($customEmbedding, $response->first());
    }

    public function testCanFakeEmbeddingsWithClosure(): void
    {
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, $prompt->dimensions, 0.1),
            $prompt->inputs
        ));

        $response = Embeddings::for(['Hello', 'World'])->dimensions(256)->generate();

        $this->assertCount(2, $response);
        $this->assertCount(256, $response->first());
    }

    public function testEmbeddingsTimeoutDefaultsToSdkFallback(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->timeout === 30);
    }

    public function testFakeEmbeddingsClosureReceivesTimeout(): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            $this->assertSame(45, $prompt->timeout);

            return array_map(
                fn (): array => array_fill(0, $prompt->dimensions, 0.1),
                $prompt->inputs
            );
        });

        Embeddings::for(['Hello world'])->timeout(45)->generate();
    }

    public function testFakeEmbeddingsPromptCarriesProviderOptions(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello'])
            ->withProviderOptions(['input_type' => 'search_query'])
            ->generate();

        Embeddings::assertGenerated(
            fn (EmbeddingsPrompt $prompt): bool => $prompt->providerOptions === ['input_type' => 'search_query'],
        );
    }

    public function testFakeQueuedEmbeddingsPromptCarriesProviderOptions(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello'])
            ->withProviderOptions(['input_type' => 'search_query'])
            ->queue();

        Embeddings::assertQueued(
            fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->providerOptions === ['input_type' => 'search_query'],
        );
    }

    public function testFakeEmbeddingsAreNormalized(): void
    {
        $embedding = Embeddings::fakeEmbedding(100);

        // Check it has the right dimensions...
        $this->assertCount(100, $embedding);

        // Check it's normalized (magnitude ~= 1)...
        $magnitude = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $embedding)));
        $this->assertEqualsWithDelta(1.0, $magnitude, 0.0001);
    }

    public function testCanPreventStrayEmbeddingsGenerations(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        Embeddings::for(['Hello world'])->generate();
    }

    public function testCanAssertEmbeddingsGenerated(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => in_array('Hello world', $prompt->inputs));
    }

    public function testCanAssertEmbeddingsNotGenerated(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->generate();

        Embeddings::assertNotGenerated(fn (EmbeddingsPrompt $prompt): bool => in_array('Goodbye', $prompt->inputs));
    }

    public function testCanAssertNothingGenerated(): void
    {
        Embeddings::fake();

        Embeddings::assertNothingGenerated();
    }

    public function testQueuedEmbeddingsCanBeFaked(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Hello'));
        Embeddings::assertNotQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Goodbye'));

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => in_array('Hello world', $prompt->inputs));

        Embeddings::assertNotQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => in_array('Goodbye', $prompt->inputs));
    }

    public function testContainsIgnoresNonTextInputs(): void
    {
        Embeddings::fake();

        Embeddings::for([
            Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        ])->generate();

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => ! $prompt->contains('Hello'));
    }

    public function testQueuedContainsIgnoresNonTextInputs(): void
    {
        Embeddings::fake();

        Embeddings::for([
            Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        ])->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => ! $prompt->contains('Hello'));
    }

    public function testQueuedEmbeddingsCanBeFakedAndThenCallbackIsExecuted(): void
    {
        Embeddings::fake([
            [
                array_fill(0, 3, 0.1),
                array_fill(0, 3, 0.2),
            ],
        ]);

        $GLOBALS['embeddingsResponse'] = null;

        Embeddings::for(['Hello world'])->queue()->then(static function (EmbeddingsResponse $response): void {
            $GLOBALS['embeddingsResponse'] = $response;
        });

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Hello'));

        $this->assertInstanceOf(EmbeddingsResponse::class, $GLOBALS['embeddingsResponse']);
        $this->assertSame([
            array_fill(0, 3, 0.1),
            array_fill(0, 3, 0.2),
        ], $GLOBALS['embeddingsResponse']->embeddings);
    }

    public function testQueuedEmbeddingsCanBeFakedAndThenCallbackIsNotExecutedIfQueueIsFaked(): void
    {
        Embeddings::fake([
            [
                array_fill(0, 3, 0.1),
                array_fill(0, 3, 0.2),
            ],
        ]);
        Queue::fake();

        $GLOBALS['embeddingsResponse'] = null;

        Embeddings::for(['Hello world'])->queue()->then(static function (EmbeddingsResponse $response): void {
            $GLOBALS['embeddingsResponse'] = $response;
        });

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Hello'));

        $this->assertNull($GLOBALS['embeddingsResponse']);

        Queue::assertPushed(GenerateEmbeddings::class);
    }

    public function testCanAssertNoEmbeddingsWereQueued(): void
    {
        Embeddings::fake();

        Embeddings::assertNothingQueued();
    }

    public function testQueuedEmbeddingsDimensionsAreRecorded(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->dimensions(256)->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->dimensions === 256 && $prompt->count() === 1);
    }

    public function testQueuedEmbeddingsTimeoutIsRecorded(): void
    {
        Embeddings::fake();

        Embeddings::for(['Hello world'])->timeout(90)->queue();

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->timeout === 90 && $prompt->count() === 1);
    }

    public function testCachedEmbeddingsWithMediaInputsUseContentHashes(): void
    {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        $directory = ParallelTesting::tempDir('AiEmbeddingsFakeTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory);
        $path = $directory . '/document.txt';

        try {
            file_put_contents($path, 'first-version');
            $calls = 0;

            Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
                ++$calls;

                return array_map(
                    fn (): array => array_fill(0, $prompt->dimensions, 0.1),
                    $prompt->inputs
                );
            });

            $request = fn (): EmbeddingsResponse => Embeddings::for([
                Document::fromPath($path),
            ])->cache(60)->generate();

            $request();
            $request();

            file_put_contents($path, 'second-version');

            $request();

            $this->assertSame(2, $calls);
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testCachedRemoteEmbeddingsDoNotFetchRemoteMetadata(): void
    {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        Http::preventStrayRequests();

        $calls = 0;

        Embeddings::fake(function () use (&$calls): array {
            ++$calls;

            return [array_fill(0, 100, 0.1)];
        });

        $request = fn (): EmbeddingsResponse => Embeddings::for([
            Document::fromUrl('https://example.com/manual.pdf'),
        ])->cache(60)->generate();

        $request();
        $request();

        $this->assertSame(1, $calls);
        Http::assertNothingSent();
    }

    public function testCachedEmbeddingsRejectUnsupportedInputTypesWithAnInvalidArgumentException(): void
    {
        config([
            'cache.default' => 'array',
            'ai.caching.embeddings.store' => 'array',
        ]);

        Embeddings::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The input at index 0 must be a string or an image, audio, document, or video file.');
        Embeddings::for([123])->cache(60)->generate();
    }

    public function testGenerateAcceptsAiProviderEnum(): void
    {
        Embeddings::fake();

        Embeddings::for(['Enum test'])->generate(provider: Lab::OpenAI);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => in_array('Enum test', $prompt->inputs));
    }

    public function testQueuedEmbeddingsAcceptAiProviderEnum(): void
    {
        Embeddings::fake();

        Embeddings::for(['Queued enum'])->queue(provider: Lab::Gemini);

        Embeddings::assertQueued(fn (QueuedEmbeddingsPrompt $prompt): bool => $prompt->contains('Queued enum')
            && $prompt->provider === Lab::Gemini);
    }

    public function testEmptyVectorsCanBeFaked(): void
    {
        Embeddings::fake([[]]);

        $response = Embeddings::for(['Document'])->generate(provider: Lab::Jina);

        $this->assertSame([], $response->embeddings);
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Embedding failed.');
        Embeddings::fake([fn (): never => throw $exception, [[0.1, 0.2]]]);

        try {
            Embeddings::for(['First'])->dimensions(2)->generate(provider: Lab::Jina);
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([[0.1, 0.2]], Embeddings::for(['Second'])->dimensions(2)->generate(provider: Lab::Jina)->embeddings);
    }
}
