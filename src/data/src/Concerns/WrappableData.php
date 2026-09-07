<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Data\Support\Wrapping\Wrap;
use Hypervel\Data\Support\Wrapping\WrapType;

trait WrappableData
{
    protected ?Wrap $wrap = null;

    /**
     * Disable wrapping for the data object.
     */
    public function withoutWrapping(): static
    {
        $this->wrap = new Wrap(WrapType::Disabled);

        return $this;
    }

    /**
     * Wrap the data object with the given key.
     */
    public function wrap(string $key): static
    {
        $this->wrap = new Wrap(WrapType::Defined, $key);

        return $this;
    }

    /**
     * Get the current wrapping definition.
     */
    public function getWrap(): Wrap
    {
        return $this->wrap ?? new Wrap(WrapType::UseGlobal);
    }
}
