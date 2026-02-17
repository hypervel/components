<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

interface Renderable
{
    /**
     * Get the evaluated contents of the object.
     */
    public function render(): string;
}
