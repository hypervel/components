<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Countable;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Support\Str;

class RerankingPrompt implements Countable
{
    /**
     * Create a new reranking prompt instance.
     *
     * @param array<int, string> $documents
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly array $documents,
        public readonly string $query,
        public readonly ?int $limit,
        public readonly RerankingProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the query contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->query, $string);
    }

    /**
     * Determine if any of the documents contain the given string.
     */
    public function documentsContain(string $string): bool
    {
        return array_any($this->documents, fn (string $document): bool => Str::contains($document, $string));
    }

    /**
     * Get the number of documents in the prompt.
     */
    public function count(): int
    {
        return count($this->documents);
    }
}
