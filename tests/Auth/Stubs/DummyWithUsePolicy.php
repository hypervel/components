<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stubs;

use Hypervel\Database\Eloquent\Attributes\UsePolicy;

#[UsePolicy(DummyWithUsePolicyPolicy::class)]
class DummyWithUsePolicy
{
}
