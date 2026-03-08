<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

class AccessGateTestPolicyWithBefore
{
    public function before($user, $ability)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return false;
    }
}
