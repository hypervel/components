<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pipeline;

use Closure;

interface Pipeline
{
    /**
     * Set the object being sent through the pipeline.
     *
     * @return $this
     */
    public function send(mixed $passable): static;

    /**
     * Set the array of pipes.
     *
     * @return $this
     */
    public function through(mixed $pipes): static;

    /**
     * Set the method to call on the pipes.
     *
     * @return $this
     */
    public function via(string $method): static;

    /**
     * Run the pipeline with a final destination callback.
     */
    public function then(Closure $destination): mixed;
}
