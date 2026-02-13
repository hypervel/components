<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Auth\Access\HandlesAuthorization;
use Hypervel\Contracts\Auth\Authenticatable;

class AccessGateTestPolicy
{
    use HandlesAuthorization;

    public function createAny($user, $additional)
    {
        return $additional;
    }

    public function create($user)
    {
        return $user->isAdmin() ? $this->allow() : $this->deny('You are not an admin.');
    }

    public function updateAny($user, AccessGateTestDummy $dummy)
    {
        return ! $user->isAdmin();
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return ! $user->isAdmin();
    }

    public function updateDash($user, AccessGateTestDummy $dummy)
    {
        return $user instanceof Authenticatable;
    }
}
