<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Closure;

trait AppendableData
{
    protected array $_additional = [];

    /**
     * Get the additional data defined by the data object.
     */
    public function with(): array
    {
        return [];
    }

    /**
     * Add top-level data to the response.
     */
    public function additional(array $additional): static
    {
        $this->_additional = array_merge($this->_additional, $additional);

        return $this;
    }

    /**
     * Get the resolved additional response data.
     */
    public function getAdditionalData(): array
    {
        $additional = $this->with();

        $computedAdditional = [];

        foreach ($additional as $name => $value) {
            $computedAdditional[$name] = $value instanceof Closure
                ? ($value)($this)
                : $value;
        }

        foreach ($this->_additional as $name => $value) {
            $computedAdditional[$name] = $value instanceof Closure
                ? ($value)($this)
                : $value;
        }

        return $computedAdditional;
    }
}
