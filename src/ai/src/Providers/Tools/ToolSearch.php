<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Tools;

use Hypervel\Ai\Contracts\Tool;
use InvalidArgumentException;

class ToolSearch extends ProviderTool
{
    /**
     * Create a tool search with deferred tools and an optional search strategy.
     *
     * @param array<Tool> $tools
     */
    public function __construct(public readonly array $tools = [], public readonly ?string $strategy = null)
    {
        if ($strategy !== null && ! in_array($strategy, ['regex', 'bm25'], true)) {
            throw new InvalidArgumentException(
                "Invalid tool search strategy [{$strategy}]. Supported strategies: regex, bm25."
            );
        }
    }

    /**
     * Get a copy of the tool search with the given deferred tools.
     *
     * @param array<Tool> $tools
     */
    public function withTools(array $tools): self
    {
        $clone = new self($tools, $this->strategy);

        $clone->providerOptions = $this->providerOptions;

        return $clone;
    }

    /**
     * Count the given tools for step budgeting, expanding each ToolSearch into its deferred tools.
     *
     * @param array<mixed> $tools
     */
    public static function budget(array $tools): int
    {
        return array_sum(array_map(
            fn (mixed $tool): int => $tool instanceof self ? count($tool->tools) : 1,
            $tools,
        ));
    }
}
