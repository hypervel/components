<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Countable;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class RerankingResponse implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new reranking response instance.
     *
     * @param array<int, RankedDocument> $results
     */
    public function __construct(
        public readonly array $results,
        public readonly RerankingUsage $usage,
        public readonly Meta $meta,
    ) {
    }

    /**
     * Get the top-ranked result.
     */
    public function first(): ?RankedDocument
    {
        return $this->results[0] ?? null;
    }

    /**
     * Get the documents in their reranked order.
     *
     * @return Collection<int, string>
     */
    public function documents(): Collection
    {
        return (new Collection($this->results))->map(fn (RankedDocument $result): string => $result->document);
    }

    /**
     * Get the number of results in the response.
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Get the results as a collection.
     *
     * @return Collection<int, RankedDocument>
     */
    public function collect(): Collection
    {
        return new Collection($this->results);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'usage' => $this->usage,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get an iterator for the results.
     *
     * @return Traversable<int, RankedDocument>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->results as $result) {
            yield $result;
        }
    }
}
