<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

class LazilyResolvedFeature
{
    /**
     * The feature class.
     *
     * @var class-string
     */
    public string $feature;

    /**
     * Create a new lazy feature instance.
     *
     * @param class-string $feature
     */
    public function __construct(string $feature)
    {
        $this->feature = $feature;
    }
}
