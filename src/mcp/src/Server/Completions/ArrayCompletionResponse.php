<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Completions;

class ArrayCompletionResponse extends CompletionResponse
{
    /**
     * Create an array-backed completion response.
     *
     * @param array<int, string> $items
     */
    public function __construct(private array $items)
    {
        parent::__construct([]);
    }

    /**
     * Resolve matching completions for the given prefix.
     */
    public function resolve(string $value): DirectCompletionResponse
    {
        $filtered = CompletionHelper::filterByPrefix($this->items, $value);

        $total = count($filtered);
        $hasMore = $total > self::MAX_VALUES;

        $truncated = array_slice($filtered, 0, self::MAX_VALUES);

        return new DirectCompletionResponse($truncated, $hasMore, $total);
    }
}
