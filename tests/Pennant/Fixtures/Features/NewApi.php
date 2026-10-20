<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Fixtures\Features;

class NewApi
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(mixed $scope): string
    {
        return 'new-api-value';
    }
}
