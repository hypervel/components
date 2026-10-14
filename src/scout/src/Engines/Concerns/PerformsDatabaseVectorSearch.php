<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines\Concerns;

use Hypervel\Ai\Embeddings;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;

trait PerformsDatabaseVectorSearch
{
    /**
     * Update the searchable embeddings for the given models.
     *
     * @param EloquentCollection<int, Model> $models
     */
    protected function updateSearchableEmbeddings(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();

        if (! method_exists($model, 'toSearchableEmbedding') || ! $this->supportsVectorSearch($model)) {
            return;
        }

        foreach (array_chunk($models->all(), 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $model) {
                // @phpstan-ignore method.notFound (The collection contains instances of the model checked above.)
                $input = $model->toSearchableEmbedding();

                // If it's already an embedding, skip it...
                if (is_array($input)) {
                    $vectors[$index] = $input;

                    continue;
                }

                if (! is_string($input) || trim($input) === '') {
                    throw new ScoutException('The [toSearchableEmbedding] method must return a non-empty string or an embedding array.');
                }

                $inputs[$index] = $input;
            }

            if (! empty($inputs)) {
                $generatedVectors = $this->generateEmbeddings(array_values($inputs));

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach ($batch as $index => $model) {
                $column = $this->embeddingColumn($model);
                $vector = $vectors[$index];

                $model->newModelQuery()
                    ->where($model->getKeyName(), $model->getKey())
                    ->toBase()
                    ->update([$column => json_encode($vector, JSON_THROW_ON_ERROR)]);

                $model->setAttribute($column, $vector);
            }
        }
    }

    /**
     * Get hybrid search results using weighted reciprocal rank fusion.
     *
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    protected function hybridSearchModels(Builder $builder, int $limit): EloquentCollection
    {
        if (! empty($builder->orders)) {
            throw new ScoutException('Database order clauses cannot be combined with hybrid search.');
        }

        if ($limit <= 0) {
            return $builder->model->newCollection();
        }

        // Generate embeddings for the query...
        $vector = $this->generateEmbeddings([$builder->query])[0];

        $column = $builder->model->qualifyColumn(
            $this->embeddingColumn($builder->model)
        );

        $baseQuery = $this->newSearchQuery($builder);

        // Build the text query...
        /** @var EloquentBuilder<Model&SearchableInterface> $textQuery */
        $textQuery = $this->constrainSearchQuery($builder, $this->addTextSearchConstraints(
            clone $baseQuery,
            $builder,
            array_keys($builder->model->toSearchableArray()),
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder)
        )->take(1000));

        // Order the text query...
        if (! $this->getFullTextColumns($builder)) {
            $textQuery->orderBy($builder->model->getQualifiedKeyName(), 'desc');
        } elseif ($this->shouldOrderByRelevance($builder)) {
            $this->orderByRelevance($builder, $textQuery);

            $textQuery->orderBy($builder->model->getQualifiedKeyName());
        }

        // Build the semantic query...
        /** @var EloquentBuilder<Model&SearchableInterface> $semanticQuery */
        $semanticQuery = $this->constrainSearchQuery($builder, (clone $baseQuery)
            ->whereVectorSimilarTo($column, $vector, $this->minimumSimilarity($builder), false)
            ->orderByVectorDistance($column, $vector)
            ->orderBy($builder->model->getQualifiedKeyName())
            ->take(1000));

        return $this->fuseSearchResults(
            $builder,
            $textQuery->get(),
            $semanticQuery->get()
        )->take($limit)->values();
    }

    /**
     * Fuse text and semantic results using weighted reciprocal rank fusion.
     *
     * @param EloquentCollection<int, Model&SearchableInterface> $textModels
     * @param EloquentCollection<int, Model&SearchableInterface> $semanticModels
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    protected function fuseSearchResults(Builder $builder, EloquentCollection $textModels, EloquentCollection $semanticModels): EloquentCollection
    {
        [$scores, $models] = [[], []];

        foreach ([
            [$textModels, $builder->hybridSearch['text_weight']],
            [$semanticModels, $builder->hybridSearch['semantic_weight']],
        ] as [$rankedModels, $weight]) {
            $seen = [];

            foreach ($rankedModels->values() as $position => $model) {
                $key = (string) $model->getKey();

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $models[$key] = $model;
                $scores[$key] = ($scores[$key] ?? 0) + ($weight / (60 + $position + 1));
            }
        }

        uksort($scores, function (int|string $left, int|string $right) use ($scores): int {
            return $scores[$right] <=> $scores[$left] ?: strcmp((string) $left, (string) $right);
        });

        return $builder->model->newCollection(
            array_map(fn (int|string $key): Model => $models[$key], array_keys($scores))
        );
    }

    /**
     * Build a semantic search query.
     */
    protected function buildSemanticSearchQuery(Builder $builder): EloquentBuilder
    {
        $this->ensureSemanticSearchIsSupported($builder);

        if (! empty($builder->orders)) {
            throw new ScoutException('Database order clauses cannot be combined with semantic search.');
        }

        $vector = $this->generateEmbeddings([$builder->query])[0];

        $column = $builder->model->qualifyColumn(
            $this->embeddingColumn($builder->model)
        );

        $query = $this->newSearchQuery($builder)
            ->whereVectorSimilarTo($column, $vector, $this->minimumSimilarity($builder), false)
            ->orderByVectorDistance($column, $vector)
            ->orderBy($builder->model->getQualifiedKeyName())
            ->take($builder->limit);

        return $this->constrainSearchQuery($builder, $query);
    }

    /**
     * Get the minimum similarity for a semantic search.
     */
    protected function minimumSimilarity(Builder $builder): float
    {
        $similarity = $builder->minimumSimilarity ?? 0.6;

        if ($similarity < 0 || $similarity > 1) {
            throw new ScoutException('The minimum similarity must be between 0 and 1.');
        }

        return $similarity;
    }

    /**
     * Apply Scout's non-search constraints to a query.
     */
    protected function constrainSearchQuery(Builder $builder, EloquentBuilder $query): EloquentBuilder
    {
        return $this->constrainForSoftDeletes($builder, $this->addAdditionalConstraints($builder, $query));
    }

    /**
     * Generate embeddings using the optional Hypervel AI SDK.
     *
     * @param array<int, string> $inputs
     * @return array<int, array<int, float|int>>
     */
    protected function generateEmbeddings(array $inputs): array
    {
        if (! class_exists(Embeddings::class)) {
            throw new ScoutException('Semantic search requires the Hypervel AI SDK. Please install the [hypervel/ai] package.');
        }

        $embeddings = Embeddings::for(array_values($inputs))
            ->cache()
            ->generate()->embeddings;

        if (count($embeddings) !== count($inputs)) {
            throw new ScoutException('Hypervel AI returned an unexpected number of embeddings.');
        }

        return $embeddings;
    }

    /**
     * Get the model's embedding column.
     */
    protected function embeddingColumn(Model $model): string
    {
        $column = method_exists($model, 'searchableEmbeddingColumn')
            ? $model->searchableEmbeddingColumn()
            : 'embedding';

        if (! is_string($column) || trim($column) === '') {
            throw new ScoutException('The [searchableEmbeddingColumn] method must return a non-empty string.');
        }

        return $column;
    }

    /**
     * Determine if a hybrid search should use vector search.
     */
    protected function shouldPerformHybridSearch(Builder $builder): bool
    {
        return $builder->hybridSearch !== null
            && method_exists($builder->model, 'toSearchableEmbedding')
            && $this->supportsVectorSearch($builder->model);
    }

    /**
     * Determine if the model's database supports vector queries.
     */
    protected function supportsVectorSearch(Model $model): bool
    {
        return $model->getConnection()->getDriverName() === 'pgsql';
    }

    /**
     * Ensure semantic search is supported by the model's database.
     */
    protected function ensureSemanticSearchIsSupported(Builder $builder): void
    {
        if (! $this->supportsVectorSearch($builder->model)) {
            throw new NotSupportedException('Database semantic search requires PostgreSQL with pgvector.');
        }

        if (! method_exists($builder->model, 'toSearchableEmbedding')) {
            throw new NotSupportedException('Database semantic search requires the model to define a [toSearchableEmbedding] method.');
        }
    }
}
