<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Ui;

use Hypervel\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class Csp implements Arrayable
{
    /**
     * Create an application content security policy.
     *
     * @param null|array<int, string> $connectDomains domains the app may connect to via fetch, XHR, or WebSocket (CSP connect-src)
     * @param null|array<int, string> $resourceDomains domains the app may load images, scripts, styles, and fonts from (CSP img-src, script-src, style-src, and font-src)
     * @param null|array<int, string> $frameDomains domains the app may embed as nested iframes (CSP frame-src)
     * @param null|array<int, string> $baseUriDomains allowed URLs for the document's base element (CSP base-uri)
     */
    public function __construct(
        protected ?array $connectDomains = null,
        protected ?array $resourceDomains = null,
        protected ?array $frameDomains = null,
        protected ?array $baseUriDomains = null,
    ) {
    }

    /**
     * Create a new content security policy.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Set the allowed connection domains.
     *
     * @param array<int, string> $domains
     */
    public function connectDomains(array $domains): static
    {
        $this->connectDomains = $domains;

        return $this;
    }

    /**
     * Set the allowed resource domains.
     *
     * @param array<int, string> $domains
     */
    public function resourceDomains(array $domains): static
    {
        $this->resourceDomains = $domains;

        return $this;
    }

    /**
     * Set the allowed frame domains.
     *
     * @param array<int, string> $domains
     */
    public function frameDomains(array $domains): static
    {
        $this->frameDomains = $domains;

        return $this;
    }

    /**
     * Set the allowed base URI domains.
     *
     * @param array<int, string> $domains
     */
    public function baseUriDomains(array $domains): static
    {
        $this->baseUriDomains = $domains;

        return $this;
    }

    /**
     * Convert the policy to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'connectDomains' => $this->connectDomains,
            'resourceDomains' => $this->resourceDomains,
            'frameDomains' => $this->frameDomains,
            'baseUriDomains' => $this->baseUriDomains,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
