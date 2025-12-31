<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Database\Eloquent\Attributes\UsePolicy;

#[UsePolicy(DummyWithUsePolicyPolicy::class)]
class DummyWithUsePolicy
{
}
