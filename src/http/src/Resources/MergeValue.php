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
        if ($data instanceof Collection) {
            $this->data = $data->all();
        } elseif ($data instanceof JsonSerializable) {
            $this->data = $data->jsonSerialize();
        } else {
            $this->data = $data;
        }
    }
}
