<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods\Concerns;

use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\ServerContext;
use InvalidArgumentException;

trait ResolvesPrompts
{
    /**
     * Resolve a registered prompt by name.
     */
    protected function resolvePrompt(?string $name, ServerContext $context): Prompt
    {
        if ($name === null || $name === '') {
            throw new InvalidArgumentException('Missing [name] parameter.');
        }

        return $context->prompts()->first(
            fn (Prompt $prompt): bool => $prompt->name() === $name,
            fn (): never => throw new InvalidArgumentException("Prompt [{$name}] not found.")
        );
    }
}
