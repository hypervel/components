<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods\Resources;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Methods\Concerns\PaginatesList;
use Hypervel\Mcp\Client\Primitives\Resource as McpResource;
use Hypervel\Support\Collection;

/**
 * @implements Method<Collection<string, McpResource>>
 */
class ListResources implements Method
{
    use PaginatesList;

    /**
     * Create a resource list request.
     */
    public function __construct(?string $cursor = null, ?int $limit = null)
    {
        $this->cursor = $cursor;
        $this->limit = $limit;
    }

    /**
     * Get the list response key.
     */
    protected function listType(): string
    {
        return 'resources';
    }

    /**
     * Create the request for the next page.
     */
    protected function nextPage(?string $cursor): static
    {
        return new static($cursor, $this->limit);
    }

    /**
     * Hydrate the listed resources by URI.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return Collection<string, McpResource>
     */
    protected function hydrate(array $payloads): Collection
    {
        return collect($payloads)->mapWithKeys(function (array $payload): array {
            $resource = McpResource::from($payload);

            return [$resource->uri => $resource];
        });
    }
}
