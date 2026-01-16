<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Contracts\Attributes\Actionable;
use Hypervel\Foundation\Testing\Contracts\Attributes\Invokable;
use Hypervel\Foundation\Testing\Features\FeaturesCollection;
use Hypervel\Support\Collection;

/**
 * Handles parsing and executing test method attributes.
 */
trait HandlesAttributes
{
    /**
     * Parse test method attributes.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     * @param class-string $attribute
     */
    protected function parseTestMethodAttributes($app, string $attribute): FeaturesCollection
    {
        $attributes = $this->resolvePhpUnitAttributes()
            ->filter(static fn ($attributes, string $key) => $key === $attribute && ! empty($attributes))
            ->flatten()
            ->map(function ($instance) use ($app) {
                if ($instance instanceof Invokable) {
                    return $instance($app);
                }

                if ($instance instanceof Actionable) {
                    return $instance->handle($app, fn ($method, $parameters) => $this->{$method}(...$parameters));
                }

                return null;
            })
            ->filter()
            ->values();

        return new FeaturesCollection($attributes);
    }

    /**
     * Resolve PHPUnit method attributes.
     *
     * @return \Hypervel\Support\Collection<class-string, array<int, object>>
     */
    abstract protected function resolvePhpUnitAttributes(): Collection;
}
