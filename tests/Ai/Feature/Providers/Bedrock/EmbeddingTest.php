<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EmbeddingTest extends TestCase
{
    use BedrockHelpers;

    public function testUnwrapsFloatVectorsWhenEmbeddingsAreKeyedByType(): void
    {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]], 'int8' => [[1, 2, 3]]],
            'response_type' => 'embeddings_by_type',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        $this->assertSame([0.1, 0.2, 0.3], $response->first());
    }

    public function testReturnsVectorsWhenEmbeddingsAreABareList(): void
    {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
        ]);

        $response = $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-english-v3',
            ['The quick brown fox.', 'Jumps over the lazy dog.'],
            1024,
        );

        $this->assertSame([0.1, 0.2, 0.3], $response->first());
        $this->assertCount(2, $response->embeddings);
    }

    public function testThrowsWhenResponseCarriesNoFloatEmbeddings(): void
    {
        $client = $this->fakeBedrockInvoke([
            'embeddings' => ['int8' => [[1, 2, 3]]],
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('only float embeddings are supported');
        $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );
    }

    public function testReturnsEmptyResponseWhenBodyCannotBeDecoded(): void
    {
        $client = $this->bedrockClient($this->bedrockInvokeMock(''));

        $response = $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        $this->assertSame([], $response->embeddings);
    }

    public function testReportsInputTokenCountFromResponseHeader(): void
    {
        $client = $this->fakeBedrockInvokeWithHeaders(
            ['embeddings' => [[0.1, 0.2, 0.3]]],
            ['x-amzn-bedrock-input-token-count' => '7'],
        );

        $response = $this->gatewayWithClient($client)->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        $this->assertSame(7, $response->usage->inputTokens);
    }

    public function testSendsCohereRequestShape(): void
    {
        $mock = $this->bedrockInvokeMock(['embeddings' => ['float' => [[0.1, 0.2, 0.3]]]]);

        $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        $this->assertSame([
            'input_type' => 'search_document',
            'texts' => ['The quick brown fox.'],
        ], json_decode($mock->getLastCommand()['body'], true));
    }

    public function testRoutesRegionPrefixedCohereModelsToCohereRequestShape(): void
    {
        $mock = $this->bedrockInvokeMock(['embeddings' => ['float' => [[0.1, 0.2, 0.3]]]]);

        $response = $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'us.cohere.embed-v4:0',
            ['The quick brown fox.'],
            1024,
        );

        $this->assertArrayHasKey('texts', json_decode($mock->getLastCommand()['body'], true));
        $this->assertSame([0.1, 0.2, 0.3], $response->first());
    }

    public function testTitanRequestsTheGivenDimensions(): void
    {
        $mock = $this->bedrockInvokeMock(['embedding' => [0.1, 0.2, 0.3], 'inputTextTokenCount' => 5]);

        $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'amazon.titan-embed-text-v2:0',
            ['The quick brown fox.'],
            256,
        );

        $this->assertSame([
            'inputText' => 'The quick brown fox.',
            'dimensions' => 256,
        ], json_decode($mock->getLastCommand()['body'], true));
    }

    #[DataProvider('titanEmbeddingTypes')]
    public function testTitanRetainsAnEmbeddingForEveryInput(array $types, array $result, array $expected): void
    {
        $mock = $this->bedrockInvokeMock($result + ['inputTextTokenCount' => 5]);
        $response = $this->gatewayWithClient($this->bedrockClient($mock))->generateEmbeddings(
            $this->bedrockProvider(),
            'amazon.titan-embed-text-v2:0',
            ['The quick brown fox.'],
            256,
            providerOptions: ['embeddingTypes' => $types],
        );

        $this->assertSame([$expected], $response->embeddings);
        $this->assertSame(5, $response->usage->inputTokens);
        $this->assertSame($types, json_decode($mock->getLastCommand()['body'], true)['embeddingTypes']);
    }

    /**
     * Provide binary-only output and the existing float preference.
     */
    public static function titanEmbeddingTypes(): array
    {
        return [
            'binary' => [['binary'], ['embeddingsByType' => ['binary' => [1, 0, 1]]], [1, 0, 1]],
            'both' => [['float', 'binary'], ['embedding' => [0.1, 0.2], 'embeddingsByType' => ['binary' => [1, 0]]], [0.1, 0.2]],
        ];
    }
}
