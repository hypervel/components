<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\SimilaritySearchTest;

use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Tools\Request;
use Hypervel\Ai\Tools\SimilaritySearch;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class SimilaritySearchTest extends TestCase
{
    public function testSearchResultsAreReturned(): void
    {
        $data = [
            [
                'id' => 1,
                'query' => 'Test query',
            ],
            [
                'id' => 2,
                'query' => 'Test query',
            ],
        ];

        $search = new SimilaritySearch(using: fn (string $query): array => $data);
        $results = $search->handle(new Request(['query' => 'Test query']));

        $this->assertStringContainsString(json_encode($data, JSON_PRETTY_PRINT), $results);
    }

    public function testSearchResultsCanBeReranked(): void
    {
        $documents = [
            ['id' => 1, 'content' => 'First document'],
            ['id' => 2, 'content' => 'Second document'],
            ['id' => 3, 'content' => 'Third document'],
        ];

        Reranking::fake([[
            new RankedDocument(index: 2, document: 'Third document', score: 0.9),
            new RankedDocument(index: 0, document: 'First document', score: 0.5),
        ]]);

        $search = (new SimilaritySearch(using: fn (string $query): array => $documents))
            ->rerank('content', limit: 2);

        $this->assertSame(
            "Relevant results found. They are listed below in order of relevance:\n\n"
            . json_encode([$documents[2], $documents[0]], JSON_PRETTY_PRINT),
            $search->handle(new Request(['query' => 'Test query'])),
        );

        Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => $prompt->query === 'Test query'
            && $prompt->limit === 2
            && $prompt->documents === ['First document', 'Second document', 'Third document']);
    }

    public function testUsingModelRejectsBlankModelClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A model class name is required for similarity search.');

        SimilaritySearch::usingModel('', 'embedding');
    }

    public function testUsingModelRejectsBlankColumnName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A vector column name is required for similarity search.');

        SimilaritySearch::usingModel(FakeVectorModel::class, '  ');
    }

    public function testUsingModelCreatesSimilaritySearch(): void
    {
        $search = SimilaritySearch::usingModel(FakeVectorModel::class, 'embedding', 0.7);
        $results = $search->handle(new Request(['query' => 'search term']));

        $this->assertStringContainsString('Relevant results found.', $results);
        $this->assertStringContainsString('First document', $results);
        $this->assertStringContainsString('Second document', $results);
    }

    public function testUsingModelAppliesCustomQueryClosure(): void
    {
        $applied = false;

        $search = SimilaritySearch::usingModel(
            FakeVectorModel::class,
            'embedding',
            0.7,
            query: function (FakeQueryBuilder $query) use (&$applied): FakeQueryBuilder {
                $applied = true;

                return $query->where('active', true);
            },
        );
        $results = $search->handle(new Request(['query' => 'search term']));

        $this->assertTrue($applied);
        $this->assertStringContainsString('Relevant results found.', $results);
    }

    public function testUsingModelExcludesEmbeddingColumnFromResults(): void
    {
        $search = SimilaritySearch::usingModel(FakeVectorModel::class, 'embedding');
        $results = $search->handle(new Request(['query' => 'search term']));

        $this->assertStringNotContainsString('embedding', $results);
        $this->assertStringContainsString('First document', $results);
    }
}

class FakeVectorModel
{
    /**
     * Create the query builder.
     */
    public static function query(): FakeQueryBuilder
    {
        return new FakeQueryBuilder;
    }
}

class FakeQueryBuilder
{
    protected array $conditions = [];

    protected ?int $limit = null;

    /**
     * Add the vector search condition.
     */
    public function whereVectorSimilarTo(string $column, string $query): self
    {
        $this->conditions['vector'] = [$column, $query];

        return $this;
    }

    /**
     * Add a query condition.
     */
    public function where(string $column, mixed $value): self
    {
        $this->conditions[$column] = $value;

        return $this;
    }

    /**
     * Limit the number of results.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Get the search results.
     */
    public function get(): Collection
    {
        return new Collection([
            new FakeModel(['id' => 1, 'content' => 'First document', 'embedding' => [0.1, 0.2, 0.3]]),
            new FakeModel(['id' => 2, 'content' => 'Second document', 'embedding' => [0.4, 0.5, 0.6]]),
        ]);
    }
}

class FakeModel extends Model
{
    protected array $guarded = [];
}
