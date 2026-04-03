<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Fixtures;

use Hypervel\JWT\Providers\Provider;

class ProviderStub extends Provider
{
    protected function isAsymmetric(): bool
    {
        return false;
    }
}
