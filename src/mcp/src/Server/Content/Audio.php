<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Content;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;

class Audio implements Content
{
    use HasMeta;

    /**
     * Create audio content from raw bytes.
     */
    public function __construct(protected string $data, protected string $mimeType = 'audio/wav')
    {
    }

    /**
     * Format the audio for a tool response.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    /**
     * Format the audio for a prompt response.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    /**
     * Format the audio for a resource response.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        return $this->mergeMeta([
            'blob' => base64_encode($this->data),
            'uri' => $resource->uri(),
            'mimeType' => $this->mimeType,
        ]);
    }

    /**
     * Get the raw audio bytes.
     */
    public function __toString(): string
    {
        return $this->data;
    }

    /**
     * Convert the audio content to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->mergeMeta([
            'type' => 'audio',
            'data' => base64_encode($this->data),
            'mimeType' => $this->mimeType,
        ]);
    }
}
