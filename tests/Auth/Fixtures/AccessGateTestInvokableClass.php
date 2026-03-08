<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

class AccessGateTestInvokableClass
{
    public function __invoke($user)
    {
        return $user->getAuthIdentifier() === 1;
    }
}
