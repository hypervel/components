<?php

declare(strict_types=1);

namespace Hypervel\View\Engines;

abstract class Engine
{
    /**
     * The view that was last to be rendered.
     */
    protected ?string $lastRendered = null;

    /**
     * Get the last view that was rendered.
     */
    public function getLastRendered(): string
    {
        return $this->lastRendered;
    }
}
