<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Events\Reranked;
use Hypervel\Ai\Events\Reranking;
use Hypervel\Ai\Reranking as RerankingFacade;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class RerankingTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'reranking')]
    public function testDocumentsCanBeReranked(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $response = RerankingFacade::of([
            'Python is a high-level, general-purpose programming language.',
            'Hypervel is a PHP web application framework with expressive, elegant syntax.',
            'React is a JavaScript library for building user interfaces.',
        ])->rerank('What is Hypervel?', provider: $provider);

        $this->assertInstanceOf(RerankingResponse::class, $response);
        $this->assertCount(3, $response);
        $this->assertSame($provider, $response->meta->provider);
        $this->assertStringContainsString('Hypervel', $response->first()->document);

        Event::assertDispatched(Reranking::class);
        Event::assertDispatched(Reranked::class);
    }

    #[DataProviderExternal(Providers::class, 'reranking')]
    public function testDocumentsCanBeRerankedWithLimit(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $response = RerankingFacade::of([
            'Django is a Python web framework.',
            'Rails is a Ruby web framework.',
            'Hypervel is a PHP web application framework.',
            'Express is a Node.js web framework.',
            'Spring is a Java web framework.',
        ])->limit(2)->rerank('PHP frameworks', provider: $provider);

        $this->assertCount(2, $response);
        $this->assertGreaterThan($response->results[1]->score, $response->first()->score);
        $this->assertStringContainsString('Hypervel', $response->first()->document);
    }

    #[DataProviderExternal(Providers::class, 'reranking')]
    public function testCollectionsCanBeRerankedUsingStringField(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $items = new Collection([
            ['id' => 1, 'content' => 'Django is a Python web framework.'],
            ['id' => 2, 'content' => 'Hypervel is a PHP web application framework.'],
            ['id' => 3, 'content' => 'React is a JavaScript library.'],
        ]);

        $reranked = $items->rerank(by: 'content', query: 'PHP frameworks', limit: 2, provider: $provider);

        $this->assertCount(2, $reranked);
        $this->assertSame(2, $reranked->first()['id']);
    }

    #[DataProviderExternal(Providers::class, 'reranking')]
    public function testCollectionsCanBeRerankedUsingArrayFields(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $items = new Collection([
            ['id' => 1, 'title' => 'Django Guide', 'body' => 'Learn Python web development.'],
            ['id' => 2, 'title' => 'Hypervel Guide', 'body' => 'Learn PHP web development.'],
            ['id' => 3, 'title' => 'React Guide', 'body' => 'Learn JavaScript UI development.'],
        ]);

        $reranked = $items->rerank(by: ['title', 'body'], query: 'PHP frameworks', limit: 2, provider: $provider);

        $this->assertCount(2, $reranked);
        $this->assertSame(2, $reranked->first()['id']);
    }

    #[DataProviderExternal(Providers::class, 'reranking')]
    public function testCollectionsCanBeRerankedUsingClosure(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $items = new Collection([
            ['id' => 1, 'title' => 'Django', 'body' => 'Python web framework.'],
            ['id' => 2, 'title' => 'Hypervel', 'body' => 'PHP web framework.'],
            ['id' => 3, 'title' => 'React', 'body' => 'JavaScript library.'],
        ]);

        $reranked = $items->rerank(
            fn (array $item): string => $item['title'] . ': ' . $item['body'],
            'PHP frameworks',
            limit: 2,
            provider: $provider
        );

        $this->assertCount(2, $reranked);
        $this->assertSame(2, $reranked->first()['id']);
    }
}
