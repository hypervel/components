<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use Hypervel\Testbench\Contracts\Attributes\Invokable;
use Hypervel\Testbench\Features\FeaturesCollection;
use Hypervel\Support\Collection;

/**
 * Handles parsing and executing test method attributes.
 */
trait HandlesAttributes
{
    /**
     * Parse and execute test method attributes of a specific type.
     *
     * Note: Attributes are already executed automatically via setUpTheTestEnvironmentUsingTestCase().
     * This method is for internal use by the testing infrastructure.
     *
     * @internal
     *
     * @param class-string $attribute
     */
    protected function parseTestMethodAttributes(ApplicationContract $app, string $attribute): FeaturesCollection
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
     * @return \Hypervel\Support\Collection<class-string, \Hypervel\Support\Collection<int, object>>
     */
    abstract protected function resolvePhpUnitAttributes(): Collection;
}
