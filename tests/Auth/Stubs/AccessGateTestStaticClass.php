<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stubs;

class AccessGateTestStaticClass
{
    public static function foo($user)
    {
        return $user->getAuthIdentifier() === 1;
    }
}
