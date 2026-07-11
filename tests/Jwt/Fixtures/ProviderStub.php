<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Fixtures;

use Hypervel\Jwt\Providers\Provider;

class ProviderStub extends Provider
{
    protected function isAsymmetric(): bool
    {
        return false;
    }
}
