<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

class AccessGateTestClass
{
    public function foo($user)
    {
        return $user->getAuthIdentifier() === 1;
    }
}
