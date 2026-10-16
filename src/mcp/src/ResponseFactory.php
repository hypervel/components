<?php

declare(strict_types=1);

namespace Hypervel\Mcp;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Concerns\HasStructuredContent;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;

class ResponseFactory
{
    use Conditionable;
    use HasMeta;
    use HasStructuredContent;
    use Macroable;

    /**
     * @var Collection<int, Response>
     */
    protected Collection $responses;

    /**
     * Create a factory for one or more responses.
     *
     * @param array<int, Response>|Response $responses
     */
    public function __construct(Response|array $responses)
    {
        $wrapped = Arr::wrap($responses);

        foreach ($wrapped as $index => $response) {
            if (! $response instanceof Response) {
                throw new InvalidArgumentException(
                    "Invalid response type at index {$index}: Expected " . Response::class . ', but received ' . get_debug_type($response) . '.'
                );
            }
        }

        $this->responses = collect($wrapped);
    }

    /**
     * Add result metadata.
     *
     * @param array<string, mixed>|string $meta
     */
    public function withMeta(string|array $meta, mixed $value = null): static
    {
        $this->setMeta($meta, $value);

        return $this;
    }

    /**
     * Add structured result content.
     *
     * @param array<string, mixed> $structuredContent
     */
    public function withStructuredContent(array $structuredContent): static
    {
        $this->setStructuredContent($structuredContent);

        return $this;
    }

    /**
     * Get the responses.
     *
     * @return Collection<int, Response>
     */
    public function responses(): Collection
    {
        return $this->responses;
    }

    /**
     * Get the result metadata.
     *
     * @return null|array<string, mixed>
     */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /**
     * Get the structured result content.
     *
     * @return null|array<string, mixed>
     */
    public function getStructuredContent(): ?array
    {
        return $this->structuredContent;
    }
}
