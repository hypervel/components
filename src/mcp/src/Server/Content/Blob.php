<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Content;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use InvalidArgumentException;

class Blob implements Content
{
    use HasMeta;

    /**
     * Create binary content.
     */
    public function __construct(protected string $content)
    {
    }

    /**
     * Reject binary content in a tool response.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        throw new InvalidArgumentException(
            'Blob content may not be used in tools.',
        );
    }

    /**
     * Reject binary content in a prompt response.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        throw new InvalidArgumentException(
            'Blob content may not be used in prompts.',
        );
    }

    /**
     * Format the binary content for a resource response.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        return $this->mergeMeta([
            'blob' => base64_encode($this->content),
            'uri' => $resource->uri(),
            'mimeType' => $resource->mimeType(),
        ]);
    }

    /**
     * Get the raw content bytes.
     */
    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * Convert the binary content to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->mergeMeta([
            'type' => 'blob',
            'blob' => $this->content,
        ]);
    }
}
