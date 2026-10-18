<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

use Hypervel\Mcp\Support\UriTemplate;

interface HasUriTemplate
{
    /**
     * Get the URI pattern for the resource template.
     */
    public function uriTemplate(): UriTemplate;
}
