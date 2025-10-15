<?php

declare(strict_types=1);

namespace Hypervel\View\Contracts;

interface Engine
{
    /**
     * Get the evaluated contents of the view.
     */
    public function get(string $path, array $data = []): string;
}
