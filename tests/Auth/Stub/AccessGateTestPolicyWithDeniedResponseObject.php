<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Auth\Access\Response;

class AccessGateTestPolicyWithDeniedResponseObject
{
    public function create()
    {
        return Response::deny('Not allowed.', 'some_code');
    }
}
