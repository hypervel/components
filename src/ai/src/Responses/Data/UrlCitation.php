<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

class UrlCitation extends Citation implements Arrayable, JsonSerializable
{
    /**
     * Create a URL citation.
     */
    public function __construct(
        public string $url,
        ?string $title = null,
        public ?int $startIndex = null,
        public ?int $endIndex = null,
    ) {
        parent::__construct($title);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'start_index' => $this->startIndex,
            'end_index' => $this->endIndex,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
