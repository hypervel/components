<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use ArrayAccess;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Collection;
use JsonException;
use JsonSerializable;
use Override;

class StructuredAgentResponse extends AgentResponse implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    use ProvidesStructuredResponse;

    /**
     * Create a structured agent response.
     */
    public function __construct(string $invocationId, array $structured, string $text, TextUsage $usage, Meta $meta)
    {
        parent::__construct($invocationId, $text, $usage, $meta);

        $this->structured = $structured;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->structured;
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->structured, $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the string representation of the object.
     */
    #[Override]
    public function __toString(): string
    {
        return (string) json_encode($this->structured);
    }
}
