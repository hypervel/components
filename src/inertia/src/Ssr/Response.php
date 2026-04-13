<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

class Response
{
    /**
     * Create a new SSR response instance.
     *
     * @param string $head the HTML head content from server-side rendering
     * @param string $body the HTML body content from server-side rendering
     */
    public function __construct(
        public readonly string $head,
        public readonly string $body,
    ) {
    }
}
