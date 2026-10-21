<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support\Fixtures;

use Hypervel\Support\Manager;

class NullableManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): ?string
    {
        return null;
    }
}
