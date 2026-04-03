<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

class AccessGateTestPolicyWithMixedPermissions
{
    public function edit($user, AccessGateTestDummy $dummy)
    {
        return false;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}
