<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stubs;

use Hypervel\Auth\Access\AuthorizationException;

class AccessGateTestPolicyThrowingAuthorizationException
{
    public function create()
    {
        throw new AuthorizationException('Not allowed.', 'some_code');
    }
}
