<?php

declare(strict_types=1);

namespace Hypervel\View\Contracts;

use Hypervel\Support\Contracts\Renderable;

interface View extends Renderable
{
    /**
     * Get the name of the view.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Add a piece of data to the view.
     *
     * @param  string|array  $key
     * @param  mixed  $value
     * @return $this
     */
    public function with(string|array $key, mixed $value = null): static;

    /**
     * Get the array of view data.
     *
     * @return array
     */
    public function getData(): array;
}
