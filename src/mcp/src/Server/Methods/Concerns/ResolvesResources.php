<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods\Concerns;

use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use InvalidArgumentException;

trait ResolvesResources
{
    /**
     * Resolve a resource by URI or URI template.
     */
    protected function resolveResource(?string $uri, ServerContext $context): Resource
    {
        if ($uri === null || $uri === '') {
            throw new InvalidArgumentException('Missing [uri] parameter.');
        }

        $resource = $context->resources()->first(fn (Resource $resource): bool => $resource->uri() === $uri)
            ?? $context->resourceTemplates()->first(fn (Resource&HasUriTemplate $template): bool => (string) $template->uriTemplate() === $uri
                || $template->uriTemplate()->match($uri) !== null);

        if (! $resource) {
            throw new InvalidArgumentException("Resource [{$uri}] not found.");
        }

        return $resource;
    }
}
