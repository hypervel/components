<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Stub;

class AccessGateTestBeforeCallback
{
    public function allowEverything($user = null)
    {
        return true;
    }

    public static function allowEverythingStatically($user = null)
    {
        return true;
    }
}
