<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods\Prompts;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Protocol;
use Hypervel\Mcp\Client\Schema\PromptResult;

/**
 * @implements Method<PromptResult>
 */
class GetPrompt implements Method
{
    /**
     * Create a prompt request.
     *
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $name,
        protected array $arguments = [],
    ) {
    }

    /**
     * Get the prompt method name.
     */
    public function method(): string
    {
        return 'prompts/get';
    }

    /**
     * Get the prompt name and arguments.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return [
            'name' => $this->name,
            'arguments' => (object) $this->arguments,
        ];
    }

    /**
     * Fetch and hydrate the prompt result.
     */
    public function handle(Protocol $protocol): PromptResult
    {
        return PromptResult::from($protocol->dispatch($this));
    }
}
