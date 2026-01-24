<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Contracts\Auth\Authenticatable;

class AccessGateTestPolicyThatAllowsGuests
{
    public function before(?Authenticatable $user)
    {
        $_SERVER['__hyperf.testBefore'] = true;
    }

    public function edit(?Authenticatable $user, AccessGateTestDummy $dummy)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}
