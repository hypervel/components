<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

use Hypervel\Mcp\Server\Completions\CompletionResponse;

interface Completable
{
    /**
     * Complete the given argument using the supplied context.
     *
     * @param array<string, mixed> $context
     */
    public function complete(string $argument, string $value, array $context): CompletionResponse;
}
