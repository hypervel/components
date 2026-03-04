<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources;

use Hypervel\Support\Collection;
use JsonSerializable;

class MergeValue
{
    /**
     * The data to be merged.
     */
    public array $data;

    /**
     * Create a new merge value instance.
     */
    public function __construct(Collection|JsonSerializable|array $data)
    {
        $this->data = match (true) {
            $data instanceof Collection => $data->all(),
            $data instanceof JsonSerializable => $data->jsonSerialize(),
            default => $data,
        };
    }
}
