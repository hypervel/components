<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Stringable;

/**
 * @extends Arrayable<array<string, mixed>>
 */
interface Content extends Arrayable, Stringable
{
    /**
     * Format the content for a tool response.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array;

    /**
     * Format the content for a prompt response.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array;

    /**
     * Format the content for a resource response.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array;

    /**
     * Set content metadata.
     *
     * @param array<string, mixed>|string $meta
     */
    public function setMeta(array|string $meta, mixed $value = null): void;

    /**
     * Get the content as a string.
     */
    public function __toString(): string;
}
