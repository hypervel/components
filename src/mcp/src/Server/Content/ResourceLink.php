<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Content;

use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use InvalidArgumentException;

class ResourceLink implements Content
{
    use HasMeta;

    /**
     * Create a resource link.
     *
     * @param array<string, mixed> $annotations
     * @param list<Icon> $icons
     */
    public function __construct(
        protected string $uri,
        protected string $name,
        protected ?string $mimeType = null,
        protected ?string $title = null,
        protected ?string $description = null,
        protected ?int $size = null,
        protected array $annotations = [],
        protected array $icons = [],
    ) {
    }

    /**
     * Format the link for a tool response.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    /**
     * Format the link for a prompt response.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    /**
     * Reject resource links in a resource response.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        throw new InvalidArgumentException(
            'ResourceLink content may not be used in resources.',
        );
    }

    /**
     * Get the resource URI.
     */
    public function __toString(): string
    {
        return $this->uri;
    }

    /**
     * Convert the resource link to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = array_filter([
            'type' => 'resource_link',
            'uri' => $this->uri,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
        ], fn (mixed $value): bool => $value !== null);

        if ($this->annotations !== []) {
            $data['annotations'] = $this->annotations;
        }

        if ($this->icons !== []) {
            $data['icons'] = array_map(fn (Icon $icon): array => $icon->toArray(), $this->icons);
        }

        return $this->mergeMeta($data);
    }
}
