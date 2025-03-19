<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

class AccessGateTestGuestInvokableClass
{
    public static $calledMethod;

    public function __invoke($user = null)
    {
        static::$calledMethod = '__invoke was called';

        return true;
    }
}
