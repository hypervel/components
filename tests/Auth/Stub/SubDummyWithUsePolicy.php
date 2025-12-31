<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Database\Eloquent\Attributes\UsePolicy;

/**
 * Extends DummyWithUsePolicy but has its own UsePolicy attribute.
 * Used to test that the attribute takes precedence over subclass fallback.
 */
#[UsePolicy(DummyWithUsePolicyPolicy::class)]
class SubDummyWithUsePolicy extends DummyWithUsePolicy
{
}
