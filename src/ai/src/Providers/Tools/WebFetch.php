<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Tools;

class WebFetch extends ProviderTool
{
    /**
     * Create a web fetch tool with optional search limits and allowed domains.
     */
    public function __construct(
        public ?int $maxSearches = null,
        public array $allowedDomains = [],
    ) {
    }

    /**
     * Set the maximum number of searches.
     */
    public function max(int $max): self
    {
        $this->maxSearches = $max;

        return $this;
    }

    /**
     * Set the allowed domains.
     */
    public function allow(array $domains): self
    {
        $this->allowedDomains = $domains;

        return $this;
    }
}
