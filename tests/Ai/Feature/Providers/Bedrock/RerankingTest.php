<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Aws\MockHandler;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RerankingTest extends TestCase
{
    use BedrockHelpers;

    /**
     * Disable external credential discovery for provider tests.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.bedrock', [
            ...$config->array('ai.providers.bedrock'),
            'use_default_credential_provider' => false,
        ]);
    }

    #[DataProvider('queries')]
    public function testRerankingRequestIncludesModelQueryAndDocuments(string $query): void
    {
        $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
        );

        Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank($query, provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

        $command = $mock->getLastCommand();

        $this->assertSame('cohere.rerank-v3-5:0', $command['modelId']);
        $this->assertSame([
            'query' => $query,
            'documents' => ['Hypervel is a PHP framework', 'React is a JS library'],
            'api_version' => 2,
        ], json_decode($command['body'], true));
    }

    /**
     * Provide ordinary and false-like search strings.
     */
    public static function queries(): array
    {
        return [['What is Hypervel?'], ['0']];
    }

    public function testRerankingRequestOmitsApiVersionForAmazonRerankModels(): void
    {
        $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
        );

        Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'bedrock', model: 'amazon.rerank-v1:0');

        $this->assertSame([
            'query' => 'What is Hypervel?',
            'documents' => ['Hypervel is a PHP framework', 'React is a JS library'],
        ], json_decode($mock->getLastCommand()['body'], true));
    }

    public function testRerankingRequestIncludesTopNWhenLimitSet(): void
    {
        $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
        );

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->limit(2)
            ->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

        $this->assertSame(2, json_decode($mock->getLastCommand()['body'], true)['top_n']);
    }

    public function testRerankingResponseIsParsedIntoRankedDocuments(): void
    {
        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->fakeBedrockInvoke(fakeBedrockRerankingResponse())),
        );

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

        $this->assertCount(2, $response);
        $this->assertInstanceOf(RankedDocument::class, $response->first());
        $this->assertSame(0, $response->first()->index);
        $this->assertSame('Hypervel is a PHP framework', $response->first()->document);
        $this->assertSame(0.95, $response->first()->score);
        $this->assertSame('bedrock', $response->meta->provider);
        $this->assertSame('cohere.rerank-v3-5:0', $response->meta->model);
    }

    public function testRerankingUsesDefaultModelWhenNoneSpecified(): void
    {
        $mock = $this->bedrockInvokeMock(fakeBedrockRerankingResponse());

        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
        );

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'bedrock');

        $this->assertSame('cohere.rerank-v3-5:0', $mock->getLastCommand()['modelId']);
    }

    public function testRerankingMapsDocumentsByIndexWhenResultsAreOutOfOrder(): void
    {
        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->fakeBedrockInvoke([
                'results' => [
                    ['index' => 2, 'relevance_score' => 0.91],
                    ['index' => 0, 'relevance_score' => 0.42],
                    ['index' => 1, 'relevance_score' => 0.10],
                ],
            ])),
        );

        $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

        $ranked = $response->collect();

        $this->assertSame(2, $ranked[0]->index);
        $this->assertSame('Doc C', $ranked[0]->document);
        $this->assertSame(0.91, $ranked[0]->score);
        $this->assertSame(0, $ranked[1]->index);
        $this->assertSame('Doc A', $ranked[1]->document);
    }

    public function testRerankingThrottlingMapsToRateLimitedException(): void
    {
        Ai::instance('bedrock')->useRerankingGateway(
            $this->rerankingGatewayWithClient($this->bedrockClient(new MockHandler([
                $this->mockBedrockException('ThrottlingException', 429),
            ]))),
        );

        $this->expectException(RateLimitedException::class);
        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');
    }
}

function fakeBedrockRerankingResponse(): array
{
    return [
        'results' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
    ];
}
