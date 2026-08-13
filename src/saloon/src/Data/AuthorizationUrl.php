<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Stringable;

final readonly class AuthorizationUrl implements Stringable
{
    /**
     * Create an authorization URL and its paired state.
     */
    public function __construct(
        public string $url,
        public string $state,
    ) {
    }

    /**
     * Convert the value to its URL.
     */
    public function __toString(): string
    {
        return $this->url;
    }
}
