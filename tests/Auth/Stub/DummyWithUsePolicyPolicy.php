<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Auth\Access\HandlesAuthorization;

class DummyWithUsePolicyPolicy
{
    use HandlesAuthorization;

    public function view($user, DummyWithUsePolicy $dummy): bool
    {
        return true;
    }

    public function update($user, DummyWithUsePolicy $dummy): bool
    {
        return true;
    }
}
