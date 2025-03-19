<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

class AccessGateTestCustomResource
{
    public function foo($user)
    {
        return true;
    }

    public function bar($user)
    {
        return true;
    }
}
