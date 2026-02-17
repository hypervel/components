<?php

declare(strict_types=1);

namespace Hypervel\View\Contracts;

use Hypervel\Contracts\Support\Renderable;

interface View extends Renderable
{
    /**
     * Get the name of the view.
     */
    public function name(): string;

    /**
     * Add a piece of data to the view.
     */
    public function with(string|array $key, mixed $value = null): static;

    /**
     * Get the array of view data.
     */
    public function getData(): array;

    /**
     * Get the path to the view file.
     */
    public function getPath(): string;
}
