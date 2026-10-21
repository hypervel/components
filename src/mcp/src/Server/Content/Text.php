<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Content;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;

class Text implements Content
{
    use HasMeta;

    /**
     * Create text content.
     */
    public function __construct(protected string $text)
    {
    }

    /**
     * Format the text for a tool response.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    /**
     * Format the text for a prompt response.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    /**
     * Format the text for a resource response.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        return $this->mergeMeta([
            'text' => $this->text,
            'uri' => $resource->uri(),
            'mimeType' => $resource->mimeType(),
        ]);
    }

    /**
     * Get the text content.
     */
    public function __toString(): string
    {
        return $this->text;
    }

    /**
     * Convert the text content to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->mergeMeta([
            'type' => 'text',
            'text' => $this->text,
        ]);
    }
}
