<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

class AccessGateTestResource
{
    public function view($user)
    {
        return true;
    }

    public function create($user)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }

    public function delete($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}
