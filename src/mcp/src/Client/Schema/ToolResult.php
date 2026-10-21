<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Schema;

use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Arr;
use Stringable;

class ToolResult implements Stringable
{
    /**
     * Create a tool call result.
     *
     * @param array<int, array<string, mixed>> $content
     * @param null|array<string, mixed> $structuredContent
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        public array $content,
        public bool $isError,
        public ?array $structuredContent = null,
        public ?array $meta = null,
    ) {
    }

    /**
     * Parse a tool call response.
     *
     * @param array<string, mixed> $result
     */
    public static function from(array $result): self
    {
        $content = Arr::get($result, 'content', []);
        $isError = Arr::get($result, 'isError', false);
        $structuredContent = Arr::get($result, 'structuredContent');
        $meta = Arr::get($result, '_meta');

        if (! is_array($content) || ! is_bool($isError)) {
            throw new ClientException('Invalid tools/call result from server.');
        }

        return new self(
            content: array_values(array_filter($content, is_array(...))),
            isError: $isError,
            structuredContent: is_array($structuredContent) ? $structuredContent : null,
            meta: is_array($meta) ? $meta : null,
        );
    }

    /**
     * Get the combined text content.
     */
    public function text(): string
    {
        $parts = [];

        foreach ($this->content as $item) {
            $text = Arr::get($item, 'text');

            if (Arr::get($item, 'type') === 'text' && is_string($text)) {
                $parts[] = $text;
            }
        }

        return implode('', $parts);
    }

    /**
     * Convert the result to its text content.
     */
    public function __toString(): string
    {
        return $this->text();
    }
}
