<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Schema;

use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Arr;
use Stringable;

class ResourceReadResult implements Stringable
{
    /**
     * Create a resource read result.
     *
     * @param array<int, array<string, mixed>> $contents
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        public readonly array $contents,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * Parse a resource read response.
     *
     * @param array<string, mixed> $result
     */
    public static function from(array $result): self
    {
        $contents = Arr::get($result, 'contents', []);
        $meta = Arr::get($result, '_meta');

        if (! is_array($contents)) {
            throw new ClientException('Invalid resources/read result from server.');
        }

        return new self(
            contents: array_values(array_filter($contents, is_array(...))),
            meta: is_array($meta) ? $meta : null,
        );
    }

    /**
     * Get the first declared MIME type.
     */
    public function mimeType(): ?string
    {
        foreach ($this->contents as $content) {
            $mimeType = Arr::get($content, 'mimeType');

            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        return null;
    }

    /**
     * Get the combined text and decoded binary content.
     */
    public function content(): string
    {
        $parts = [];

        foreach ($this->contents as $content) {
            $text = Arr::get($content, 'text');

            if (is_string($text)) {
                $parts[] = $text;

                continue;
            }

            $blob = Arr::get($content, 'blob');

            if (is_string($blob)) {
                $decoded = base64_decode($blob, true);

                if ($decoded !== false) {
                    $parts[] = $decoded;
                }
            }
        }

        return implode('', $parts);
    }

    /**
     * Convert the result to its content.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
