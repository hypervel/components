<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

readonly class RerankingUsage extends Usage
{
    /**
     * Create reranking usage data.
     *
     * @param int $inputTokens total tokens across the query and the documents
     * @param null|float $searchUnits billed search units, or null when unreported
     */
    public function __construct(
        int $inputTokens = 0,
        public ?float $searchUnits = null,
    ) {
        parent::__construct($inputTokens);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'search_units' => $this->searchUnits,
        ];
    }
}
