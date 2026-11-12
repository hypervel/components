<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualBindingBuilder as ContextualBindingBuilderContract;

class ContextualBindingBuilder implements ContextualBindingBuilderContract
{
    /**
     * The abstract target.
     */
    protected string $needs;

    /**
     * Create a new contextual binding builder.
     */
    public function __construct(
        protected Container $container,
        protected string|array $concrete,
    ) {
    }

    /**
     * Define the abstract target that depends on the context.
     *
     * @return $this
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
     *
     * @return $this
     */
    public function give(mixed $implementation): static
    {
        foreach (Util::arrayWrap($this->concrete) as $concrete) {
            $this->container->addContextualBinding($concrete, $this->needs, $implementation);
        }

        return $this;
    }

    /**
     * Define tagged services to be used as the implementation for the contextual binding.
     *
     * @return $this
     */
    public function giveTagged(string $tag): static
    {
        return $this->give(function ($container) use ($tag) {
            $taggedServices = $container->tagged($tag);

            return is_array($taggedServices) ? $taggedServices : iterator_to_array($taggedServices);
        });
    }

    /**
     * Specify the configuration item to bind as a primitive.
     *
     * @return $this
     */
    public function giveConfig(string $key, mixed $default = null): static
    {
        return $this->give(fn ($container) => $container->make('config')->get($key, $default));
    }
}
