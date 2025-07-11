<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

use Hyperf\ViewEngine\Contract\Renderable as BaseRenderable;

interface Renderable extends BaseRenderable
{
    /**
     * Get the evaluated contents of the object.
     */
    public function render(): string;
}
