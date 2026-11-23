<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Container;

interface ContextualBindingBuilder
{
    /**
     * Define the abstract target that depends on the context.
     *
     * @return $this
     */
    public function needs(string $abstract): static;

    /**
     * Define the implementation for the contextual binding.
     *
     * @return $this
     */
    public function give(mixed $implementation): static;

    /**
     * Define tagged services to be used as the implementation for the contextual binding.
     *
     * @return $this
     */
    public function giveTagged(string $tag): static;

    /**
     * Specify the configuration item to bind as a primitive.
     *
     * @return $this
     */
    public function giveConfig(string $key, mixed $default = null): static;
}
