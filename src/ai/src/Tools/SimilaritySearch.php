<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools;

use Closure;
use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use InvalidArgumentException;

class SimilaritySearch implements Approvable, Tool, Transient
{
    use InteractsWithApprovals;

    protected ?string $description = null;

    protected bool $rerank = false;

    protected Closure|array|string|null $rerankBy = null;

    protected ?int $rerankLimit = null;

    /**
     * Create a new similarity search tool instance.
     */
    public function __construct(
        public Closure $using,
    ) {
    }

    /**
     * Create a new similarity search tool instance.
     *
     * @param class-string<Model> $model
     *
     * @throws InvalidArgumentException if the given model class name or vector column name is blank
     */
    public static function usingModel(
        string $model,
        string $column,
        float $minSimilarity = 0.6,
        int $limit = 15,
        ?Closure $query = null
    ): self {
        if (blank($model)) {
            throw new InvalidArgumentException('A model class name is required for similarity search.');
        }

        if (blank($column)) {
            throw new InvalidArgumentException('A vector column name is required for similarity search.');
        }

        return new self(function (string $queryString) use ($model, $column, $minSimilarity, $limit, $query): Collection {
            $pendingQuery = $model::query()->whereVectorSimilarTo($column, $queryString, $minSimilarity);

            if ($query instanceof Closure) {
                $pendingQuery = $query($pendingQuery);
            }

            if ($limit !== 0) {
                $pendingQuery->limit($limit);
            }

            return $pendingQuery
                ->get()
                ->map(fn (Model $model): array => Arr::except($model->toArray(), [$column]));
        });
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return $this->description ?? 'Search for documents similar to a given query.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $results = call_user_func($this->using, (string) $request->string('query'));

        $results = match (true) {
            is_array($results) => new Collection($results),
            $results instanceof Collection => $results,
            default => $results->get(),
        };

        if ($results->isEmpty()) {
            return 'No relevant results found.';
        }

        if ($this->rerank) {
            $results = $results->rerank(
                $this->rerankBy,
                (string) $request->string('query'),
                limit: $this->rerankLimit
            );
        }

        return "Relevant results found. They are listed below in order of relevance:\n\n"
            . $results->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Set the tool's description.
     */
    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Indicate that the results should be reranked.
     */
    public function rerank(Closure|array|string $by, ?int $limit = null): self
    {
        $this->rerank = true;
        $this->rerankBy = $by;
        $this->rerankLimit = $limit;

        return $this;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The search query.')
                ->required(),
        ];
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
