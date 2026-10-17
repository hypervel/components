<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Completions;

class DirectCompletionResponse extends CompletionResponse
{
    /**
     * Return the already resolved completion response.
     */
    public function resolve(string $value): DirectCompletionResponse
    {
        return $this;
    }
}
