<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

use Hypervel\Contracts\Auth\Authenticatable;

class AccessGateTestClassForGuest
{
    public static $calledMethod;

    public function foo($user = null)
    {
        static::$calledMethod = 'foo was called';

        return true;
    }

    public static function staticFoo($user = null)
    {
        static::$calledMethod = 'static foo was invoked';

        return true;
    }

    public function bar(?Authenticatable $user)
    {
        static::$calledMethod = 'bar got invoked';

        return true;
    }
}
