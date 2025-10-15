<?php

namespace Hypervel\View\Engines;

abstract class Engine
{
    /**
     * The view that was last to be rendered.
     *
     * @var string
     */
    protected ?string $lastRendered = null;

    /**
     * Get the last view that was rendered.
     *
     * @return string
     */
    public function getLastRendered(): ?string
    {
        return $this->lastRendered;
    }
}
