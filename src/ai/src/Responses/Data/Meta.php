<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use JsonSerializable;

class Meta implements Arrayable, JsonSerializable
{
    /** @var Collection<int, Citation> */
    public Collection $citations;

    /**
     * Create response metadata.
     *
     * @param null|Collection<int, Citation> $citations
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        ?Collection $citations = null,
    ) {
        $this->citations = $citations ?? new Collection;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'citations' => $this->citations->all(),
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
