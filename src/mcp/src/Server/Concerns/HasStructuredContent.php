<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Concerns;

trait HasStructuredContent
{
    /**
     * @var null|array<string, mixed>
     */
    protected ?array $structuredContent = null;

    /**
     * Set structured response content.
     *
     * @param array<string, mixed> $structuredContent
     */
    public function setStructuredContent(array $structuredContent): void
    {
        $this->structuredContent ??= [];

        $this->structuredContent = array_merge($this->structuredContent, $structuredContent);
    }

    /**
     * Merge structured content into the given response array.
     *
     * @param array<string, mixed> $baseArray
     * @return array<string, mixed>
     */
    public function mergeStructuredContent(array $baseArray): array
    {
        if ($this->structuredContent === null) {
            return $baseArray;
        }

        return array_merge($baseArray, ['structuredContent' => $this->structuredContent === [] ? (object) [] : $this->structuredContent]);
    }
}
