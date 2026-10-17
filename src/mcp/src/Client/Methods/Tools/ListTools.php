<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods\Tools;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Methods\Concerns\PaginatesList;
use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Mcp\Exceptions\MirroredParameterException;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Log;

/**
 * @implements Method<Collection<string, Tool>>
 */
class ListTools implements Method
{
    use PaginatesList;

    /**
     * Create a tool list request.
     */
    public function __construct(
        protected ?Client $client = null,
        ?string $cursor = null,
        ?int $limit = null,
    ) {
        $this->cursor = $cursor;
        $this->limit = $limit;
    }

    /**
     * Get the list response key.
     */
    protected function listType(): string
    {
        return 'tools';
    }

    /**
     * Create the request for the next page.
     */
    protected function nextPage(?string $cursor): static
    {
        return new static($this->client, $cursor, $this->limit);
    }

    /**
     * Hydrate supported tools by name.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return Collection<string, Tool>
     */
    protected function hydrate(array $payloads): Collection
    {
        $tools = [];

        foreach ($payloads as $payload) {
            try {
                $tool = Tool::from($this->client, $payload);
            } catch (MirroredParameterException $mirroredParameterException) {
                $name = is_string($payload['name'] ?? null) ? $payload['name'] : 'unknown';

                Log::warning("Excluded the MCP tool [{$name}] from the tool list because {$mirroredParameterException->getMessage()}.");

                continue;
            }

            $tools[$tool->name] = $tool;
        }

        return collect($tools);
    }
}
