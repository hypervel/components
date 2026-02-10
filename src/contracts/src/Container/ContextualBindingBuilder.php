<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Container;

interface ContextualBindingBuilder
{
    /**
     * Define the abstract target that depends on the context.
     */
    public function needs(string $abstract): static;

    /**
     * Define the implementation for the contextual binding.
     */
    public function give(mixed $implementation): static;

    /**
     * Define tagged services to be used as the implementation for the contextual binding.
     */
    public function giveTagged(string $tag): static;

    /**
     * Specify the configuration item to bind as a primitive.
     */
    public function giveConfig(string $key, mixed $default = null): static;
}
