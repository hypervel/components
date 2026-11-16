<?php

declare(strict_types=1);

namespace Hypervel\Contracts\View;

use Hypervel\Contracts\Support\Renderable;

interface View extends Renderable
{
    /**
     * Get the name of the view.
     */
    public function name(): string;

    /**
     * Add a piece of data to the view.
     *
     * @return $this
     */
    public function with(string|array $key, mixed $value = null): static;

    /**
     * Get the array of view data.
     */
    public function getData(): array;

    /**
     * Get the data used to render the view.
     *
     * Unlike Laravel, the contract declares this method because response assertions
     * inspect rendering data, including shared values, through the view contract.
     */
    public function gatherData(): array;

    /**
     * Get the path to the view file.
     */
    public function getPath(): string;
}
