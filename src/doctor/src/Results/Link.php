<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

use Hypervel\Support\Str;

final readonly class Link
{
    /**
     * Create a new related link.
     */
    private function __construct(
        public string $label,
        public string $url,
        public string $agentUrl,
    ) {
    }

    /**
     * Create a link to an arbitrary URL.
     */
    public static function to(string $label, string $url): self
    {
        return new self($label, $url, $url);
    }

    /**
     * Create a versionless link to a Hypervel documentation page.
     */
    public static function docs(string $page, ?string $section = null, ?string $label = null): self
    {
        return self::to(
            $label ?? Str::headline($page) . ' documentation',
            'https://hypervel.org/docs/' . $page . ($section !== null ? '#' . $section : ''),
        );
    }

    /**
     * Replace tokens in the link's label and URLs.
     *
     * @param array<string, string> $tokens
     */
    public function replace(array $tokens): self
    {
        return new self(
            Str::swap($tokens, $this->label),
            Str::swap($tokens, $this->url),
            Str::swap($tokens, $this->agentUrl),
        );
    }
}
