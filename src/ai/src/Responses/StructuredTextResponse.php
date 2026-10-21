<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use ArrayAccess;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Support\Collection;
use Override;

class StructuredTextResponse extends TextResponse implements ArrayAccess
{
    use ProvidesStructuredResponse;

    /**
     * Create a structured text response.
     */
    public function __construct(array $structured, string $text, public TextUsage $usage, public Meta $meta)
    {
        parent::__construct($text, $usage, $meta);

        $this->structured = $structured;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
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
