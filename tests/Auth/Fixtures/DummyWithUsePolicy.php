<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Attributes\UsePolicy;

#[UsePolicy(DummyWithUsePolicyPolicy::class)]
class DummyWithUsePolicy
{
}
