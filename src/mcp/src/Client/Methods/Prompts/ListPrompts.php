<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods\Prompts;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Methods\Concerns\PaginatesList;
use Hypervel\Mcp\Client\Primitives\Prompt;
use Hypervel\Support\Collection;

/**
 * @implements Method<Collection<string, Prompt>>
 */
class ListPrompts implements Method
{
    use PaginatesList;

    /**
     * Create a prompt list request.
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
        return 'prompts';
    }

    /**
     * Create the request for the next page.
     */
    protected function nextPage(?string $cursor): static
    {
        return new static($cursor, $this->limit);
    }

    /**
     * Hydrate the listed prompts by name.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return Collection<string, Prompt>
     */
    protected function hydrate(array $payloads): Collection
    {
        return collect($payloads)->mapWithKeys(function (array $payload): array {
            $prompt = Prompt::from($payload);

            return [$prompt->name => $prompt];
        });
    }
}
