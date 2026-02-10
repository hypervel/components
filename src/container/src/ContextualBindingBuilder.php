<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Closure;
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
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
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
     */
    public function giveConfig(string $key, mixed $default = null): static
    {
        return $this->give(fn ($container) => $container->get('config')->get($key, $default));
    }
}
